<?php

namespace AutoModerator\Tests\Hooks;

use AutoModerator\Config\AutoModeratorWikiConfigLoader;
use AutoModerator\Config\WikiPageConfig;
use AutoModerator\Hooks\RevisionFromEditCompleteHookHandler;
use MediaWiki\ChangeTags\ChangeTags;
use MediaWiki\Config\HashConfig;
use MediaWiki\Page\WikiPage;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Permissions\RestrictionStore;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentity;

/**
 * @group AutoModerator
 * @group Database
 * @covers \AutoModerator\Hooks\RevisionFromEditCompleteHookHandler
 */
class RevisionFromEditCompleteHookHandlerTest extends \MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
	}

	public function provideOnRevisionFromEditCompleteQueued(): array {
		$wikiPage = $this->createMock( WikiPage::class );
		$wikiPage->method( 'getId' )->willReturn( 1 );
		$wikiPage->method( 'getNamespace' )->willReturn( NS_MAIN );
		$wikiPage->method( 'getTitle' )->willReturn( $this->createMock( Title::class ) );
		$mockSlotRecord = $this->createMock( SlotRecord::class );
		$mockSlotRecord->method( 'getModel' )->willReturn( "wikitext" );
		$rev = $this->createMock( RevisionRecord::class );
		$rev->method( 'getId' )->willReturn( 1000 );
		$rev->method( 'getParentId' )->willReturn( 999 );
		$rev->method( 'getSlot' )->willReturn( $mockSlotRecord );
		$user = $this->createMock( UserIdentity::class );
		$user->method( 'getId' )->willReturn( 1000 );
		$user->method( 'getName' )->willReturn( 'TestUser1000' );

		return [
			[ $wikiPage, $rev, false, $user, [] ]
		];
	}

	public function provideOnRevisionFromEditCompleteQueuedTalkPageMessageJob(): array {
		$wikiPage = $this->createMock( WikiPage::class );
		$wikiPage->method( 'getNamespace' )->willReturn( NS_MAIN );
		$wikiPage->method( 'getId' )->willReturn( 1 );
		$wikiPage->method( 'getTitle' )->willReturn( $this->createMock( Title::class ) );
		$rev = $this->createMock( RevisionRecord::class );
		$rev->method( 'getUser' )->willReturn( $this->createMock( User::class ) );
		$rev->method( 'getId' )->willReturn( 1000 );
		$rev->method( 'getParentId' )->willReturn( 999 );
		$user = $this->createMock( UserIdentity::class );
		$user->method( 'getId' )->willReturn( 1000 );
		$user->method( 'getName' )->willReturn( 'TestUser1000' );
		return [
			[ $wikiPage, $rev, false, $user, [ ChangeTags::TAG_ROLLBACK ] ]
		];
	}

	/**
	 * @dataProvider provideOnRevisionFromEditCompleteQueued
	 */
	public function testOnRevisionFromEditCompleteQueued( $wikiPage, $rev, $originalRevId, $user, $tags ) {
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
				'revertrisklanguageagnostic' => [ 'enabled' => false, 'namespaces' => [ 0 ] ]
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
		$mockPermissionManager = $this->createMock( PermissionManager::class );

		( new RevisionFromEditCompleteHookHandler( $autoModWikiConfig, $userGroupManager,
			$config, $wikiPageFactory, $mockRevisionStore, $mockRestrictionStore,
			$jobQueueGroup, $mockPermissionManager ) )
			->onRevisionFromEditComplete( $wikiPage, $rev, $originalRevId, $user, $tags );

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
			'scores' => null
		];
		$this->assertEquals( $expected, $actual );
	}

	/**
	 * @dataProvider provideOnRevisionFromEditCompleteQueued
	 */
	public function testOnRevisionFromEditCompleteQueuedWhenUserAnon( $wikiPage, $rev, $originalRevId, $user, $tags ) {
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
			'DisableAnonTalk' => true,
			'AutoModeratorEnableWikiConfig' => true,
			'AutoModeratorEnableRevisionCheck' => true,
			'AutoModeratorUsername' => 'AutoModerator',
			'AutoModeratorWikiId' => 'enwiki',
			'OresModels' => [
				'revertrisklanguageagnostic' => [ 'enabled' => false, 'namespaces' => [ 0 ] ]
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
		$mockPermissionManager = $this->createMock( PermissionManager::class );

		( new RevisionFromEditCompleteHookHandler( $autoModWikiConfig, $userGroupManager,
			$config, $wikiPageFactory, $mockRevisionStore, $mockRestrictionStore, $jobQueueGroup,
			$mockPermissionManager ) )
			->onRevisionFromEditComplete( $wikiPage, $rev, $originalRevId, $user, $tags );

		$actual = $jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->pop()->getParams();
		$actual['requestId'] = 42;
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
			'scores' => null
		];
		$this->assertEquals( $expected, $actual );
	}

	public function provideOnRevisionFromEditCompleteMainNotQueued() {
		$wikiPage = $this->createMock( WikiPage::class );
		$wikiPage->method( 'getNamespace' )->willReturn( NS_MAIN );
		$rev = $this->createMock( RevisionRecord::class );
		$user = $this->createMock( UserIdentity::class );
		return [
			[ null, $rev, false, $user, [] ],
			[ $wikiPage, null, false, $user, [] ],
			[ $wikiPage, $rev, false, null, [] ],
		];
	}

	/**
	 * @dataProvider provideOnRevisionFromEditCompleteMainNotQueued
	 */
	public function testOnRevisionFromEditCompleteMainNotQueued( $wikiPage, $rev, $originalRevId, $user, $tags ) {
		$jobQueueGroup = $this->getServiceContainer()->getJobQueueGroup();
		$jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->delete();
		$wikiPageFactory = $this->getServiceContainer()->getWikiPageFactory();
		$wikiConfig = $this->createMock( WikiPageConfig::class );
		$wikiConfig->expects( $this->never() )->method( 'hasWithFlags' );
		$wikiConfig->expects( $this->never() )->method( 'getWithFlags' );
		$autoModWikiConfig = new AutoModeratorWikiConfigLoader(
			$wikiConfig,
			new HashConfig( [
				'AutoModeratorEnableWikiConfig' => true,
				'AutoModeratorEnableRevisionCheck' => true,
				'AutoModeratorUsername' => 'AutoModerator',
			] )
		);
		$config = new HashConfig( [
			'AutoModeratorEnableWikiConfig' => true,
			'AutoModeratorEnableRevisionCheck' => true,
			'AutoModeratorUsername' => 'AutoModerator',
			'OresModels' => [
				'revertrisklanguageagnostic' => [ 'enabled' => false, 'namespaces' => [ 0 ] ]
			]
		] );
		$userGroupManager = $this->createMock( UserGroupManager::class );
		$mockRevision = $this->createMock( RevisionRecord::class );
		$mockRevision->method( 'getParentId' )->willReturn( 100 );
		$mockRevisionStore = $this->createMock( RevisionStore::class );
		$mockRevisionStore->method( 'getRevisionById' )->willReturn( $mockRevision );
		$mockRestrictionStore = $this->createMock( RestrictionStore::class );
		$mockRestrictionStore->method( 'isProtected' )->willReturn( false );
		$mockPermissionManager = $this->createMock( PermissionManager::class );

		( new RevisionFromEditCompleteHookHandler( $autoModWikiConfig, $userGroupManager,
			$config, $wikiPageFactory, $mockRevisionStore, $mockRestrictionStore, $jobQueueGroup,
			$mockPermissionManager ) )
			->onRevisionFromEditComplete( $wikiPage, $rev, $originalRevId, $user, $tags );

		$this->assertFalse( $jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->pop() );
	}

	public function provideOnRevisionFromEditCompleteTalkNotQueued() {
		$wikiPage = $this->createMock( WikiPage::class );
		$wikiPage->method( 'getNamespace' )->willReturn( NS_TALK );
		$rev = $this->createMock( RevisionRecord::class );
		$user = $this->createMock( UserIdentity::class );
		return [
			[ $wikiPage, $rev, false, $user, [] ]
		];
	}

	/**
	 * @dataProvider provideOnRevisionFromEditCompleteTalkNotQueued
	 */
	public function testOnRevisionFromEditCompleteTalkNotQueued( $wikiPage, $rev, $originalRevId, $user, $tags ) {
		$jobQueueGroup = $this->getServiceContainer()->getJobQueueGroup();
		$jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->delete();
		$wikiPageFactory = $this->createMock( WikiPageFactory::class );
		$wikiPageFactory->method( 'newFromID' )->willReturn( $wikiPage );
		$wikiConfig = $this->createMock( WikiPageConfig::class );
		$wikiConfig->expects( $this->atLeastOnce() )->method( 'hasWithFlags' );
		$wikiConfig->expects( $this->never() )->method( 'getWithFlags' );
		$wikiConfig->method( "get" )->willReturn( true );
		$autoModWikiConfig = new AutoModeratorWikiConfigLoader(
			$wikiConfig,
			new HashConfig( [
				'AutoModeratorEnableWikiConfig' => true,
				'AutoModeratorEnableRevisionCheck' => true,
				'AutoModeratorUsername' => 'AutoModerator',
				'AutoModeratorSkipUserRights' => [ 'bot', 'autopatrol' ],
			] )
		);
		$config = new HashConfig( [
			'DisableAnonTalk' => true,
			'AutoModeratorEnableWikiConfig' => true,
			'AutoModeratorEnableRevisionCheck' => true,
			'AutoModeratorUsername' => 'AutoModerator',
			'AutoModeratorSkipUserRights' => [ 'bot', 'autopatrol' ],
			'OresModels' => [
				'revertrisklanguageagnostic' => [ 'enabled' => false, 'namespaces' => [ 0 ] ]
			]
		] );
		$userGroupManager = $this->createMock( UserGroupManager::class );
		$mockRevisionStore = $this->createMock( RevisionStore::class );
		$mockRevision = $this->createMock( RevisionRecord::class );
		$mockRestrictionStore = $this->createMock( RestrictionStore::class );

		$mockRevision->method( 'getId' )->willReturn( 101 );
		$mockRevision->method( 'getParentId' )->willReturn( 100 );
		$mockRevisionStore->method( 'getRevisionById' )->willReturn( $mockRevision );
		$mockRestrictionStore->method( 'isProtected' )->willReturn( false );
		$mockPermissionManager = $this->createMock( PermissionManager::class );

		( new RevisionFromEditCompleteHookHandler( $autoModWikiConfig, $userGroupManager,
			$config, $wikiPageFactory, $mockRevisionStore, $mockRestrictionStore, $jobQueueGroup,
			$mockPermissionManager ) )
			->onRevisionFromEditComplete( $wikiPage, $mockRevision, $originalRevId, $user, $tags );

		$this->assertFalse( $jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->pop() );
	}
}
