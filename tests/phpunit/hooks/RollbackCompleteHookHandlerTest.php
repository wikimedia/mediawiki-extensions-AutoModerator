<?php

namespace AutoModerator\Tests\Hooks;

use AutoModerator\Config\AutoModeratorWikiConfigLoader;
use AutoModerator\Config\WikiPageConfig;
use AutoModerator\Hooks\RollbackCompleteHookHandler;
use AutoModerator\TalkPageMessageSender;
use AutoModerator\Util;
use MediaWiki\Config\HashConfig;
use MediaWiki\Page\WikiPage;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentity;

/**
 * @group AutoModerator
 * @group Database
 * @covers \AutoModerator\Hooks\RevisionFromEditCompleteHookHandler
 */
class RollbackCompleteHookHandlerTest extends \MediaWikiIntegrationTestCase {

	private function prepareMocks( bool $needsWikiPage, ?array $revSpec, ?array $rollbackRevisionSpec ) {
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
			if ( $revSpec['slot'] ?? false ) {
				$mockSlotRecord = $this->createMock( SlotRecord::class );
				$mockSlotRecord->method( 'getModel' )->willReturn( "wikitext" );
				$rev->method( 'getSlot' )->willReturn( $mockSlotRecord );
			}
		} else {
			$rev = null;
		}

		if ( $rollbackRevisionSpec !== null ) {
			$rollbackRevision = $this->createMock( RevisionRecord::class );
			if ( array_key_exists( 'id', $rollbackRevisionSpec ) ) {
				$rollbackRevision->method( 'getId' )->willReturn( $rollbackRevisionSpec['id'] );
			}
			if ( array_key_exists( 'parentId', $rollbackRevisionSpec ) ) {
				$rollbackRevision->method( 'getParentId' )->willReturn( $rollbackRevisionSpec['parentId'] );
			}
			if ( $rollbackRevisionSpec['slot'] ?? false ) {
				$mockSlotRecord = $this->createMock( SlotRecord::class );
				$mockSlotRecord->method( 'getModel' )->willReturn( "wikitext" );
				$rollbackRevision->method( 'getSlot' )->willReturn( $mockSlotRecord );
			}
			$wikiPage->method( 'getRevisionRecord' )->willReturn( $rollbackRevision );
		} else {
			$rollbackRevision = null;
		}

		return [ $wikiPage, $rev, $rollbackRevision ];
	}

	public static function provideOnRollbackComplete(): array {
		$revSpec = [
			'id' => 999,
			'parentId' => 998,
			'slot' => true,
		];

		$rollbackRevisionSpec = [
			'id' => 1000,
			'parentId' => 999,
			'slot' => true,
		];

		return [
			[ true, $revSpec, $rollbackRevisionSpec ]
		];
	}

	/**
	 * @dataProvider provideOnRollbackComplete
	 */
	public function testOnRollbackComplete(
		bool $needsWikiPage, ?array $revSpec, ?array $rollbackRevisionSpec
	) {
		[ $wikiPage, $rev, $rollbackRevision ] = $this->prepareMocks( $needsWikiPage, $revSpec, $rollbackRevisionSpec );

		$jobQueueGroup = $this->getServiceContainer()->getJobQueueGroup();
		$wikiConfig = $this->createMock( WikiPageConfig::class );
		$wikiConfig->expects( $this->never() )->method( 'getWithFlags' );
		$wikiConfig->method( "get" )->willReturn( true );
		$revisionStore = $this->createMock( RevisionStore::class );
		$revisionStore->method( 'getRevisionById' )->willReturn( $rev );

		$autoModWikiConfig = new AutoModeratorWikiConfigLoader(
			$wikiConfig,
			new HashConfig( [
				'AutoModeratorEnableWikiConfig' => true,
				'AutoModeratorEnableRevisionCheck' => true,
				'AutoModeratorUsername' => 'AutoModerator',
				'AutoModeratorRevertTalkPageMessageEnabled' => true,
				'AutoModeratorFalsePositivePageTitle' => Title::newFromText( __METHOD__ ),
				'AutoModeratorMultilingualConfigEnableMultilingual' => false
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
			],
			'TranslateNumerals' => false,
			'AutoModeratorMultiLingualRevertRisk' => false
		] );
		$userGroupManager = $this->createMock( UserGroupManager::class );
		$titleFactory = $this->createMock( TitleFactory::class );
		$titleFactory->method( 'newFromText' )->willReturn( $this->createMock( Title::class ) );

		$talkPageMessageSender = new TalkPageMessageSender(
			$revisionStore,
			$config,
			$autoModWikiConfig,
			$jobQueueGroup,
			$titleFactory
		);
		$user = Util::getAutoModeratorUser( $config, $userGroupManager );

		( new RollbackCompleteHookHandler( $autoModWikiConfig, $userGroupManager, $config,
			$talkPageMessageSender ) )
			->onRollbackComplete( $wikiPage, $user, $rev, $rev );

		$actual = $jobQueueGroup->get( 'AutoModeratorSendRevertTalkPageMsgJob' )->pop()->getParams();
		$this->assertEquals( $rev->getId(), $actual['revId'] );
		$this->assertEquals( $rollbackRevision->getId(), $actual['rollbackRevId'] );
		$this->assertEquals( $user->getId(), $actual['autoModeratorUserId'] );
		$this->assertEquals( $user->getName(), $actual['autoModeratorUserName'] );
	}

	/**
	 * @dataProvider provideOnRollbackComplete
	 */
	public function testOnRollbackCompleteDoesNotQueueWhenDisabled(
		bool $needsWikiPage, ?array $revSpec, ?array $rollbackRevisionSpec
	) {
		[ $wikiPage, $rev, $rollbackRevision ] = $this->prepareMocks( $needsWikiPage, $revSpec, $rollbackRevisionSpec );

		$jobQueueGroup = $this->getServiceContainer()->getJobQueueGroup();
		$wikiConfig = $this->createMock( WikiPageConfig::class );
		$wikiConfig->expects( $this->never() )->method( 'getWithFlags' );
		$wikiConfig->method( "get" )->willReturn( true );
		$revisionStore = $this->createMock( RevisionStore::class );
		$revisionStore->method( 'getRevisionById' )->willReturn( $rev );

		$autoModWikiConfig = new AutoModeratorWikiConfigLoader(
			$wikiConfig,
			new HashConfig( [
				'AutoModeratorEnableWikiConfig' => true,
				'AutoModeratorEnableRevisionCheck' => true,
				'AutoModeratorUsername' => 'AutoModerator',
				'AutoModeratorRevertTalkPageMessageEnabled' => false,
				'AutoModeratorFalsePositivePageTitle' => Title::newFromText( __METHOD__ ),
				'AutoModeratorMultilingualConfigEnableMultilingual' => false
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
			],
			'TranslateNumerals' => false,
			'AutoModeratorMultiLingualRevertRisk' => false
		] );
		$userGroupManager = $this->createMock( UserGroupManager::class );
		$titleFactory = $this->getServiceContainer()->getTitleFactory();

		$talkPageMessageSender = new TalkPageMessageSender(
			$revisionStore,
			$config,
			$autoModWikiConfig,
			$jobQueueGroup,
			$titleFactory
		);
		$user = Util::getAutoModeratorUser( $config, $userGroupManager );

		( new RollbackCompleteHookHandler( $autoModWikiConfig, $userGroupManager, $config,
			$talkPageMessageSender ) )
			->onRollbackComplete( $wikiPage, $user, $rev, $rev );

		$actual = $jobQueueGroup->get( 'AutoModeratorSendRevertTalkPageMsgJob' )->pop();
		$this->assertFalse( $actual );
	}

	/**
	 * @dataProvider provideOnRollbackComplete
	 */
	public function testOnRollbackCompleteDoesNotQueueWhenUserIsNotAutoModerator(
		bool $needsWikiPage, ?array $revSpec, ?array $rollbackRevisionSpec
	) {
		[ $wikiPage, $rev, $rollbackRevision ] = $this->prepareMocks( $needsWikiPage, $revSpec, $rollbackRevisionSpec );

		$jobQueueGroup = $this->getServiceContainer()->getJobQueueGroup();
		$wikiConfig = $this->createMock( WikiPageConfig::class );
		$wikiConfig->expects( $this->never() )->method( 'getWithFlags' );
		$wikiConfig->method( "get" )->willReturn( true );
		$revisionStore = $this->createMock( RevisionStore::class );
		$revisionStore->method( 'getRevisionById' )->willReturn( $rev );

		$autoModWikiConfig = new AutoModeratorWikiConfigLoader(
			$wikiConfig,
			new HashConfig( [
				'AutoModeratorEnableWikiConfig' => true,
				'AutoModeratorEnableRevisionCheck' => true,
				'AutoModeratorUsername' => 'AutoModerator',
				'AutoModeratorRevertTalkPageMessageEnabled' => false,
				'AutoModeratorFalsePositivePageTitle' => Title::newFromText( __METHOD__ ),
				'AutoModeratorMultilingualConfigEnableMultilingual' => false
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
			],
			'TranslateNumerals' => false
		] );
		$userGroupManager = $this->createMock( UserGroupManager::class );
		$titleFactory = $this->getServiceContainer()->getTitleFactory();

		$talkPageMessageSender = new TalkPageMessageSender(
			$revisionStore,
			$config,
			$autoModWikiConfig,
			$jobQueueGroup,
			$titleFactory
		);
		$user = $this->createMock( UserIdentity::class );
		$user->method( 'getId' )->willReturn( 1001 );
		$user->method( 'getName' )->willReturn( __METHOD__ );

		( new RollbackCompleteHookHandler( $autoModWikiConfig, $userGroupManager, $config,
			$talkPageMessageSender ) )
			->onRollbackComplete( $wikiPage, $user, $rev, $rev );

		$actual = $jobQueueGroup->get( 'AutoModeratorSendRevertTalkPageMsgJob' )->pop();
		$this->assertFalse( $actual );
	}
}
