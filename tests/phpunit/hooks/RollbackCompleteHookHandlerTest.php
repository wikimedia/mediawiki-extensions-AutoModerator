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
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentity;

/**
 * @group AutoModerator
 * @group Database
 * @covers \AutoModerator\Hooks\RevisionFromEditCompleteHookHandler
 */
class RollbackCompleteHookHandlerTest extends \MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
	}

	public function provideOnRollbackComplete(): array {
		$wikiPage = $this->createMock( WikiPage::class );
		$wikiPage->method( 'getId' )->willReturn( 1 );
		$wikiPage->method( 'getNamespace' )->willReturn( NS_MAIN );
		$wikiPage->method( 'getTitle' )->willReturn( $this->createMock( Title::class ) );
		$mockSlotRecord = $this->createMock( SlotRecord::class );
		$mockSlotRecord->method( 'getModel' )->willReturn( "wikitext" );
		$rev = $this->createMock( RevisionRecord::class );
		$rev->method( 'getId' )->willReturn( 999 );
		$rev->method( 'getParentId' )->willReturn( 998 );
		$rev->method( 'getSlot' )->willReturn( $mockSlotRecord );

		$rollbackRevision = $this->createMock( RevisionRecord::class );
		$rollbackRevision->method( 'getId' )->willReturn( 1000 );
		$rollbackRevision->method( 'getParentId' )->willReturn( 999 );
		$rollbackRevision->method( 'getSlot' )->willReturn( $mockSlotRecord );

		$wikiPage->method( 'getRevisionRecord' )->willReturn( $rollbackRevision );
		return [
			[ $wikiPage, $rev, $rollbackRevision ]
		];
	}

	/**
	 * @dataProvider provideOnRollbackComplete
	 */
	public function testOnRollbackComplete( $wikiPage, $rev, $rollbackRevision ) {
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

		$talkPageMessageSender = new TalkPageMessageSender(
			$revisionStore,
			$config,
			$autoModWikiConfig,
			$jobQueueGroup,
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
	public function testOnRollbackCompleteDoesNotQueueWhenDisabled( $wikiPage, $rev, $rollbackRevision ) {
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

		$talkPageMessageSender = new TalkPageMessageSender(
			$revisionStore,
			$config,
			$autoModWikiConfig,
			$jobQueueGroup,
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
	public function testOnRollbackCompleteDoesNotQueueWhenUserIsNotAutoModerator( $wikiPage, $rev, $rollbackRevision ) {
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

		$talkPageMessageSender = new TalkPageMessageSender(
			$revisionStore,
			$config,
			$autoModWikiConfig,
			$jobQueueGroup,
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
