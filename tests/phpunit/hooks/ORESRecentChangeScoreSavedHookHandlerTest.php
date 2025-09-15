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

	public static function provideOnOresRecentChangesScoreSavedQueued(): array {
		$revSpec = [
			'id' => 1000,
			'parentId' => 999,
			'pageId' => 1,
			'slot' => true,
			'user' => [ 1000, 'TestUser1000' ],
		];

		return [
			[ true, $revSpec ]
		];
	}

	/**
	 * @dataProvider provideOnOresRecentChangesScoreSavedQueued
	 */
	public function testOnOresRecentChangesScoreSavedQueued( $needsWikiPage, $revSpec ) {
		$this->markTestSkippedIfExtensionNotLoaded( 'ORES' );
		$this->overrideConfigValue( 'OresModels', [
			'revertrisklanguageagnostic' => [ 'enabled' => true, 'namespaces' => [ 0 ] ]
		] );

		[ $wikiPage, $rev ] = $this->prepareMocks( $needsWikiPage, $revSpec );
		$user = $rev->getUser();

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
				'AutoModeratorMultilingualConfigEnableMultilingual' => false
			] )
		);
		$config = new HashConfig( [
			'DisableAnonTalk' => false,
			'AutoModeratorEnableWikiConfig' => true,
			'AutoModeratorEnableRevisionCheck' => true,
			'AutoModeratorUsername' => 'AutoModerator',
			'AutoModeratorWikiId' => 'enwiki',
			'AutoModeratorMultiLingualRevertRisk' => false,
			'OresModels' => [
				'revertrisklanguageagnostic' => [ 'enabled' => true, 'namespaces' => [ 0 ] ]
			],
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

	public static function provideOnOresRecentChangesScoreSavedNotQueued(): array {
		$revSpec = [
			'id' => 1000,
			'parentId' => 100,
			'pageId' => 1,
			'slot' => true,
			'user' => [ 1000, 'TestUser1000' ],
		];
		$revNoUserSpec = [
			'user' => null,
		];
		$revNoPageIdSpec = [
			'user' => [ 1000, 'TestUser1000' ],
			'pageId' => null,
		];

		return [
			[ true, $revSpec, false ],
			[ true, null, true ],
			[ false, $revSpec, true ],
			[ true, $revNoUserSpec, true ],
			[ true, $revNoPageIdSpec, true ]
		];
	}

	/**
	 * @dataProvider provideOnOresRecentChangesScoreSavedNotQueued
	 */
	public function testOnOresRecentChangesScoreSavedNotQueued(
		bool $needsWikiPage, ?array $revSpec, bool $needsScore
	) {
		$this->markTestSkippedIfExtensionNotLoaded( 'ORES' );
		$this->overrideConfigValue( 'OresModels', [
			'revertrisklanguageagnostic' => [ 'enabled' => true, 'namespaces' => [ 0 ] ]
		] );

		[ $wikiPage, $rev, $scores ] = $this->prepareMocks( $needsWikiPage, $revSpec, $needsScore );

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
			'AutoModeratorMultiLingualRevertRisk' => false,
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

	private function prepareMocks( bool $needsWikiPage, ?array $revSpec, bool $needsScore = false ) {
		if ( $needsWikiPage ) {
			$wikiPage = $this->createMock( WikiPage::class );
			$wikiPage->method( 'getId' )->willReturn( 1 );
			$wikiPage->method( 'getNamespace' )->willReturn( NS_MAIN );
			$wikiPage->method( 'getTitle' )->willReturn( $this->createMock( Title::class ) );
		} else {
			$wikiPage = null;
		}

		if ( $revSpec !== null ) {
			$rev = $this->createMock( RevisionRecord::class );
			if ( array_key_exists( 'id', $revSpec ) ) {
				$rev->method( 'getId' )->willReturn( $revSpec['id'] );
			}
			if ( array_key_exists( 'parentId', $revSpec ) ) {
				$rev->method( 'getParentId' )->willReturn( $revSpec['parentId'] );
			}
			if ( array_key_exists( 'pageId', $revSpec ) ) {
				$rev->method( 'getPageId' )->willReturn( $revSpec['pageId'] );
			}
			if ( $revSpec['slot'] ?? false ) {
				$mockSlotRecord = $this->createMock( SlotRecord::class );
				$mockSlotRecord->method( 'getModel' )->willReturn( "wikitext" );
				$rev->method( 'getSlot' )->willReturn( $mockSlotRecord );
			}
			if ( array_key_exists( 'user', $revSpec ) ) {
				if ( $revSpec['user'] !== null ) {
					[ $id, $name ] = $revSpec['user'];
					$user = $this->createMock( UserIdentity::class );
					$user->method( 'getId' )->willReturn( $id );
					$user->method( 'getName' )->willReturn( $name );
				} else {
					$user = null;
				}
				$rev->method( 'getUser' )->willReturn( $user );
			}
		} else {
			$rev = null;
		}
		if ( $needsScore ) {
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
		} else {
			$scores = null;
		}
		return [ $wikiPage, $rev, $scores ];
	}

}
