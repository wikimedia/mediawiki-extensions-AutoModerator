<?php

namespace AutoModerator\Tests\Hooks;

use AutoModerator\Config\AutoModeratorWikiConfigLoader;
use AutoModerator\Config\WikiPageConfig;
use AutoModerator\Hooks\ORESRecentChangeScoreSavedHookHandler;
use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\Config\HashConfig;
use MediaWiki\Page\WikiPage;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Permissions\RestrictionStore;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentity;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * @group AutoModerator
 * @group Database
 * @covers \AutoModerator\Hooks\ORESRecentChangeScoreSavedHookHandler
 */
class ORESRecentChangeScoreSavedHookHandlerTest extends \MediaWikiIntegrationTestCase {

	public function provideOnOresRecentChangesScoreSavedQueued(): array {
		$wikiPage = $this->createMock( WikiPage::class );
		$wikiPage->method( 'getId' )->willReturn( 1 );
		$wikiPage->method( 'getNamespace' )->willReturn( NS_MAIN );
		$wikiPage->method( 'getTitle' )->willReturn( $this->createMock( Title::class ) );
		$mockSlotRecord = $this->createMock( SlotRecord::class );
		$mockSlotRecord->method( 'getModel' )->willReturn( "wikitext" );
		$user = $this->createMock( UserIdentity::class );
		$user->method( 'getId' )->willReturn( 1000 );
		$user->method( 'getName' )->willReturn( 'TestUser1000' );
		$rev = $this->createMock( RevisionRecord::class );
		$rev->method( 'getId' )->willReturn( 1000 );
		$rev->method( 'getParentId' )->willReturn( 999 );
		$rev->method( 'getPageId' )->willReturn( 1 );
		$rev->method( 'getSlot' )->willReturn( $mockSlotRecord );
		$rev->method( 'getUser' )->willReturn( $user );

		return [
			[ $wikiPage, $rev, $user ]
		];
	}

	/**
	 * @dataProvider provideOnOresRecentChangesScoreSavedQueued
	 */
	public function testOnOresRecentChangesScoreSavedQueued( $wikiPage, $rev, $user ) {
		$this->markTestSkippedIfExtensionNotLoaded( 'ORES' );
		$this->overrideConfigValue( 'OresModels', [
			'revertrisklanguageagnostic' => [ 'enabled' => true, 'namespaces' => [ 0 ] ]
		] );
		$jobQueueGroup = $this->getServiceContainer()->getJobQueueGroup();
		$jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->delete();
		$wikiConfig = $this->createMock( WikiPageConfig::class );
		$wikiConfig->expects( $this->never() )->method( 'getWithFlags' );
		$wikiConfig->method( "get" )->willReturn( true );
		$autoModWikiConfig = new AutoModeratorWikiConfigLoader(
			$wikiConfig,
			new HashConfig( [
				'AutoModeratorEnableWikiConfig' => true,
				'AutoModeratorEnableRevisionCheck' => true,
				'AutoModeratorUsername' => 'AutoModerator',
				'AutoModeratorSkipUserRights' => [],
			] )
		);
		$config = new HashConfig( [
			'DisableAnonTalk' => false,
			'AutoModeratorEnableWikiConfig' => true,
			'AutoModeratorEnableRevisionCheck' => true,
			'AutoModeratorUsername' => 'AutoModerator',
			'AutoModeratorWikiId' => 'enwiki',
			'OresModels' => [
				'revertrisklanguageagnostic' => [ 'enabled' => true, 'namespaces' => [ 0 ] ]
			]
		] );
		$userGroupManager = $this->createMock( UserGroupManager::class );
		$wikiPageFactory = $this->createMock( WikiPageFactory::class );
		$wikiPageFactory->method( 'newFromID' )->willReturn( $wikiPage );
		$mockRevision = $this->createMock( RevisionRecord::class );
		$mockRevisionStore = $this->createMock( RevisionStore::class );
		$mockRevisionStore->method( 'getRevisionById' )->with( $rev->getParentId() )->willReturn( $mockRevision );
		$mockRestrictionStore = $this->createMock( RestrictionStore::class );
		$mockRestrictionStore->method( 'isProtected' )->willReturn( false );
		$mockChangeTagsStore = $this->createMock( ChangeTagsStore::class );
		$mockChangeTagsStore->method( 'getTags' )->willReturn( [] );
		$mockPermissionManager = $this->createMock( PermissionManager::class );
		$mockConnectionProvider = $this->createMock( IConnectionProvider::class );
		$score = [
			'model_name' => 'revertrisklanguageagnostic',
			'model_version' => '0.0.1',
			'wiki_db' => $wikiPage->getId(),
			'revision_id' => $rev->getId(),
			'output' => [
				'probabilities' => [
					'true' => 0.998
				],
			],
		];

		( new ORESRecentChangeScoreSavedHookHandler(
			$autoModWikiConfig,
			$userGroupManager,
			$config,
			$wikiPageFactory,
			$mockRevisionStore,
			$mockRestrictionStore,
			$jobQueueGroup,
			$mockChangeTagsStore,
			$mockPermissionManager,
			$mockConnectionProvider
			)
		)
			->onORESRecentChangeScoreSavedHook( $rev, [ $score ] );

		$actual = $jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->pop()->getParams();
		$actual['requestId'] = 42;
		// Disabling line too long rule as line is too long for phpcs,
		// but we need to check for strict equality without newline breaks
		// phpcs:disable Generic.Files.LineLength.TooLong
		// phpcs:enable
		$expected = [
			'wikiPageId' => 1,
			'revId' => 1000,
			'originalRevId' => false,
			'userId' => $user->getId(),
			'userName' => $user->getName(),
			'tags' => [],
			'namespace' => NS_MAIN,
			'title' => '',
			'requestId' => 42,
			'scores' => [ $score ]
		];
		$this->assertEquals( $expected, $actual );
	}

	public function provideOnOresRecentChangesScoreSavedNotQueued(): array {
		$wikiPage = $this->createMock( WikiPage::class );
		$wikiPage->method( 'getId' )->willReturn( 1 );
		$wikiPage->method( 'getNamespace' )->willReturn( NS_MAIN );
		$wikiPage->method( 'getTitle' )->willReturn( $this->createMock( Title::class ) );
		$mockSlotRecord = $this->createMock( SlotRecord::class );
		$mockSlotRecord->method( 'getModel' )->willReturn( "wikitext" );
		$user = $this->createMock( UserIdentity::class );
		$user->method( 'getId' )->willReturn( 1000 );
		$user->method( 'getName' )->willReturn( 'TestUser1000' );
		$rev = $this->createMock( RevisionRecord::class );
		$rev->method( 'getId' )->willReturn( 1000 );
		$rev->method( 'getParentId' )->willReturn( 100 );
		$rev->method( 'getPageId' )->willReturn( 1 );
		$rev->method( 'getSlot' )->willReturn( $mockSlotRecord );
		$rev->method( 'getUser' )->willReturn( $user );
		$revNoUser = $this->createMock( RevisionRecord::class );
		$revNoUser->method( 'getUser' )->willReturn( null );
		$revNoPageId = $this->createMock( RevisionRecord::class );
		$revNoPageId->method( 'getUser' )->willReturn( $user );
		$revNoPageId->method( 'getPageId' )->willReturn( null );
		$scores = [
			[
				'model_name' => 'revertrisklanguageagnostic',
				'model_version' => '0.0.1',
				'wiki_db' => 21879,
				'revision_id' => 2312,
				'output' => [
					'probabilities' => [
						'true' => 0.998
					],
				],
			]
		];

		return [
			[ $wikiPage, $rev, null ],
			[ $wikiPage, null, $scores ],
			[ null, $rev, $scores ],
			[ $wikiPage, $revNoUser, $scores ],
			[ $wikiPage, $revNoPageId, $scores ]
		];
	}

	/**
	 * @dataProvider provideOnOresRecentChangesScoreSavedNotQueued
	 */
	public function testOnOresRecentChangesScoreSavedNotQueued( $wikiPage, $rev, $scores ) {
		$this->markTestSkippedIfExtensionNotLoaded( 'ORES' );
		$this->overrideConfigValue( 'OresModels', [
			'revertrisklanguageagnostic' => [ 'enabled' => true, 'namespaces' => [ 0 ] ]
		] );
		$jobQueueGroup = $this->getServiceContainer()->getJobQueueGroup();
		$jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->delete();
		$wikiConfig = $this->createMock( WikiPageConfig::class );
		$wikiConfig->expects( $this->never() )->method( 'getWithFlags' );
		$wikiConfig->method( "get" )->willReturn( true );
		$autoModWikiConfig = new AutoModeratorWikiConfigLoader(
			$wikiConfig,
			new HashConfig( [
				'AutoModeratorEnableWikiConfig' => true,
				'AutoModeratorEnableRevisionCheck' => true,
				'AutoModeratorUsername' => 'AutoModerator',
				'AutoModeratorSkipUserGroups' => [],
			] )
		);
		$config = new HashConfig( [
			'DisableAnonTalk' => false,
			'AutoModeratorEnableWikiConfig' => true,
			'AutoModeratorEnableRevisionCheck' => true,
			'AutoModeratorUsername' => 'AutoModerator',
			'AutoModeratorWikiId' => 'enwiki',
			'OresModels' => [
				'revertrisklanguageagnostic' => [ 'enabled' => true, 'namespaces' => [ 0 ] ]
			]
		] );
		$userGroupManager = $this->createMock( UserGroupManager::class );
		$wikiPageFactory = $this->createMock( WikiPageFactory::class );
		$wikiPageFactory->method( 'newFromID' )->willReturn( $wikiPage );
		$mockRevision = $this->createMock( RevisionRecord::class );
		$mockRevisionStore = $this->createMock( RevisionStore::class );
		if ( $rev ) {
			$mockRevisionStore->method( 'getRevisionById' )
				->with( $rev->getParentId() )->willReturn( $mockRevision );
		}
		$mockRestrictionStore = $this->createMock( RestrictionStore::class );
		$mockRestrictionStore->method( 'isProtected' )->willReturn( false );
		$mockChangeTagsStore = $this->createMock( ChangeTagsStore::class );
		$mockChangeTagsStore->method( 'getTags' )->willReturn( [] );
		$mockPermissionManager = $this->createMock( PermissionManager::class );
		$mockConnectionProvider = $this->createMock( IConnectionProvider::class );

		( new ORESRecentChangeScoreSavedHookHandler(
			$autoModWikiConfig,
			$userGroupManager,
			$config,
			$wikiPageFactory,
			$mockRevisionStore,
			$mockRestrictionStore,
			$jobQueueGroup,
			$mockChangeTagsStore,
			$mockPermissionManager,
			$mockConnectionProvider
			)
		)
			->onORESRecentChangeScoreSavedHook( $rev, $scores );

		$this->assertFalse( $jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->pop() );
	}

}
