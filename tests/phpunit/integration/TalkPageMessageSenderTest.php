<?php

namespace AutoModerator\Tests;

use AutoModerator\Config\AutoModeratorWikiConfigLoader;
use AutoModerator\Config\WikiPageConfig;
use AutoModerator\TalkPageMessageSender;
use AutoModerator\Util;
use MediaWiki\Config\HashConfig;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Title\Title;
use MediaWiki\User\UserGroupManager;
use Psr\Log\LoggerInterface;

/**
 * @group AutoModerator
 * @group Database
 * @covers \AutoModerator\TalkPageMessageSender
 */
class TalkPageMessageSenderTest extends \MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
	}

	/**
	 * @covers AutoModerator\TalkPageMessageSender::insertAutoModeratorSendRevertTalkPageMsgJob
	 */
	public function testTalkPageMessageSenderNotQueuedRevIdNull() {
		$title = $this->createMock( Title::class );
		$wikiPageId = 42;
		$revId = null;
		$wikiConfig = $this->createMock( WikiPageConfig::class );
		$jobQueueGroup = $this->getServiceContainer()->getJobQueueGroup();
		$jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->delete();
		$autoModWikiConfig = new AutoModeratorWikiConfigLoader(
			$wikiConfig,
			new HashConfig( [
				'AutoModeratorEnableWikiConfig' => true,
				'AutoModeratorEnableRevisionCheck' => true,
				'AutoModeratorUsername' => 'AutoModerator',
				'AutoModeratorRevertTalkPageMessageEnabled' => true,
				'AutoModeratorRevertTalkPageMessageHeading' => "heading",
				'AutoModeratorRevertTalkPageMessageEditSummary' => "edit summary",
				'AutoModeratorFalsePositivePageTitle' => "",

			] )
		);
		$config = new HashConfig( [
			'AutoModeratorEnableWikiConfig' => true,
			'AutoModeratorEnableRevisionCheck' => true,
			'AutoModeratorUsername' => 'AutoModerator',
			'AutoModeratorWikiId' => "en"
		] );
		$mockRevisionStore = $this->createMock( RevisionStore::class );
		$logger = $this->createMock( LoggerInterface::class );

		$userGroupManager = $this->createMock( UserGroupManager::class );
		$autoModeratorUser = Util::getAutoModeratorUser( $config, $userGroupManager );

		$talkPageMessageSender = new TalkPageMessageSender( $mockRevisionStore, $config,
			$autoModWikiConfig, $jobQueueGroup );
		$talkPageMessageSender->insertAutoModeratorSendRevertTalkPageMsgJob( $title, $wikiPageId, $revId,
			$autoModeratorUser, $logger );

		$this->assertFalse( $jobQueueGroup->get( 'AutoModeratorSendRevertTalkPageMsgJob' )->pop() );
	}

	/**
	 * @covers AutoModerator\TalkPageMessageSender::insertAutoModeratorSendRevertTalkPageMsgJob
	 */
	public function testTalkPageMessageSenderNotQueuedRevNull() {
		$title = $this->createMock( Title::class );
		$wikiPageId = 42;
		$revId = 1;
		$mockRevStore = $this->createMock( RevisionStore::class );
		$mockRevStore->method( 'getRevisionById' )->willReturn( null );
		$wikiConfig = $this->createMock( WikiPageConfig::class );
		$jobQueueGroup = $this->getServiceContainer()->getJobQueueGroup();
		$jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->delete();
		$autoModWikiConfig = new AutoModeratorWikiConfigLoader(
			$wikiConfig,
			new HashConfig( [
				'AutoModeratorEnableWikiConfig' => true,
				'AutoModeratorEnableRevisionCheck' => true,
				'AutoModeratorUsername' => 'AutoModerator',
				'AutoModeratorRevertTalkPageMessageEnabled' => true,
				'AutoModeratorRevertTalkPageMessageHeading' => "heading",
				'AutoModeratorRevertTalkPageMessageEditSummary' => "edit summary",
				'AutoModeratorFalsePositivePageTitle' => "",

			] )
		);
		$config = new HashConfig( [
			'AutoModeratorEnableWikiConfig' => true,
			'AutoModeratorEnableRevisionCheck' => true,
			'AutoModeratorUsername' => 'AutoModerator',
			'AutoModeratorWikiId' => "en"
		] );
		$mockRevisionStore = $this->createMock( RevisionStore::class );
		$logger = $this->createMock( LoggerInterface::class );

		$userGroupManager = $this->createMock( UserGroupManager::class );
		$autoModeratorUser = Util::getAutoModeratorUser( $config, $userGroupManager );

		$talkPageMessageSender = new TalkPageMessageSender( $mockRevisionStore, $config,
			$autoModWikiConfig, $jobQueueGroup );
		$talkPageMessageSender->insertAutoModeratorSendRevertTalkPageMsgJob( $title, $wikiPageId, $revId,
			$autoModeratorUser, $logger );

		$this->assertFalse( $jobQueueGroup->get( 'AutoModeratorSendRevertTalkPageMsgJob' )->pop() );
	}

	/**
	 * @covers AutoModerator\TalkPageMessageSender::insertAutoModeratorSendRevertTalkPageMsgJob
	 */
	public function testTalkPageMessageSenderNotQueuedParentRevIdNull() {
		$title = $this->createMock( Title::class );
		$wikiPageId = 42;
		$revId = 1;
		$mockRevStore = $this->createMock( RevisionStore::class );
		$mockRevStore->method( 'getRevisionById' )->willReturn( $revId );
		$mockRevRecord = $this->createMock( RevisionRecord::class );
		$mockRevRecord->method( 'getParentId' )->willReturn( null );
		$wikiConfig = $this->createMock( WikiPageConfig::class );
		$jobQueueGroup = $this->getServiceContainer()->getJobQueueGroup();
		$jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->delete();
		$autoModWikiConfig = new AutoModeratorWikiConfigLoader(
			$wikiConfig,
			new HashConfig( [
				'AutoModeratorEnableWikiConfig' => true,
				'AutoModeratorEnableRevisionCheck' => true,
				'AutoModeratorUsername' => 'AutoModerator',
				'AutoModeratorRevertTalkPageMessageEnabled' => true,
				'AutoModeratorRevertTalkPageMessageHeading' => "heading",
				'AutoModeratorRevertTalkPageMessageEditSummary' => "edit summary",
				'AutoModeratorFalsePositivePageTitle' => "",

			] )
		);
		$config = new HashConfig( [
			'AutoModeratorEnableWikiConfig' => true,
			'AutoModeratorEnableRevisionCheck' => true,
			'AutoModeratorUsername' => 'AutoModerator',
			'AutoModeratorWikiId' => "en"
		] );
		$mockRevisionStore = $this->createMock( RevisionStore::class );
		$logger = $this->createMock( LoggerInterface::class );

		$userGroupManager = $this->createMock( UserGroupManager::class );
		$autoModeratorUser = Util::getAutoModeratorUser( $config, $userGroupManager );

		$talkPageMessageSender = new TalkPageMessageSender( $mockRevisionStore, $config,
			$autoModWikiConfig, $jobQueueGroup );
		$talkPageMessageSender->insertAutoModeratorSendRevertTalkPageMsgJob( $title, $wikiPageId, $revId,
			$autoModeratorUser, $logger );

		$this->assertFalse( $jobQueueGroup->get( 'AutoModeratorSendRevertTalkPageMsgJob' )->pop() );
	}

	/**
	 * @covers AutoModerator\TalkPageMessageSender::insertAutoModeratorSendRevertTalkPageMsgJob
	 */
	public function testTalkPageMessageSenderQueued() {
		$title = $this->createMock( Title::class );
		$wikiPageId = 42;
		$revId = 94;
		$mockRevisionStore = $this->createMock( RevisionStore::class );
		$mockRevRecord = $this->createMock( RevisionRecord::class );
		$mockRevisionStore->method( 'getRevisionById' )->willReturn( $mockRevRecord );
		$mockRevRecord->method( 'getParentId' )->willReturn( 93 );
		$wikiConfig = $this->createMock( WikiPageConfig::class );
		$jobQueueGroup = $this->getServiceContainer()->getJobQueueGroup();
		$jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->delete();
		$autoModWikiConfig = new AutoModeratorWikiConfigLoader(
			$wikiConfig,
			new HashConfig( [
				'AutoModeratorEnableWikiConfig' => true,
				'AutoModeratorEnableRevisionCheck' => true,
				'AutoModeratorUsername' => 'AutoModerator',
				'AutoModeratorRevertTalkPageMessageEnabled' => true,
				'AutoModeratorRevertTalkPageMessageHeading' => "heading",
				'AutoModeratorRevertTalkPageMessageEditSummary' => "edit summary",
				'AutoModeratorFalsePositivePageTitle' => "",

			] )
		);
		$config = new HashConfig( [
			'AutoModeratorEnableWikiConfig' => true,
			'AutoModeratorEnableRevisionCheck' => true,
			'AutoModeratorUsername' => 'AutoModerator',
			'AutoModeratorWikiId' => "en"
		] );
		$logger = $this->createMock( LoggerInterface::class );

		$userGroupManager = $this->createMock( UserGroupManager::class );
		$autoModeratorUser = Util::getAutoModeratorUser( $config, $userGroupManager );

		$talkPageMessageSender = new TalkPageMessageSender( $mockRevisionStore, $config,
			$autoModWikiConfig, $jobQueueGroup );
		$talkPageMessageSender->insertAutoModeratorSendRevertTalkPageMsgJob( $title, $wikiPageId, $revId,
			$autoModeratorUser, $logger );

		$actual = $jobQueueGroup->get( 'AutoModeratorSendRevertTalkPageMsgJob' )->pop()->getParams();
		$actual['requestId'] = 99;
		$expected = [
			'wikiPageId' => 42,
			'revId' => 94,
			'parentRevId' => 93,
			'autoModeratorUserId' => 1,
			'autoModeratorUserName' => 'AutoModerator',
			'talkPageMessageHeader' => '== {{CURRENTMONTHNAME}} {{CURRENTYEAR}}: AutoModerator reverted your edit ==',
			'talkPageMessageEditSummary' => 'Notice of automated revert on [[]]',
			'falsePositiveReportPageId' => '',
			'wikiId' => 'en',
			'namespace' => 0,
			'title' => '',
			'requestId' => 99

		];

		$this->assertEquals( $expected, $actual );
	}

}
