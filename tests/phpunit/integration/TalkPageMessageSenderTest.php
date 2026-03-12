<?php

namespace AutoModerator\Tests;

use AutoModerator\TalkPageMessageSender;
use AutoModerator\Util;
use MediaWiki\Config\HashConfig;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\UserGroupManager;
use Psr\Log\LoggerInterface;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group AutoModerator
 * @group Database
 * @covers \AutoModerator\TalkPageMessageSender
 */
class TalkPageMessageSenderTest extends \MediaWikiIntegrationTestCase {

	/**
	 * @covers AutoModerator\TalkPageMessageSender::insertAutoModeratorSendRevertTalkPageMsgJob
	 */
	public function testTalkPageMessageSenderNotQueuedRevIdDoesNotExist() {
		$title = $this->createMock( Title::class );
		$jobQueueGroup = $this->getServiceContainer()->getJobQueueGroup();
		$jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->delete();
		$config = new HashConfig( [
			'AutoModeratorEnableRevisionCheck' => true,
			'AutoModeratorUsername' => 'AutoModerator',
			'AutoModeratorRevertTalkPageMessageEnabled' => true,
			'AutoModeratorRevertTalkPageMessageHeading' => "heading",
			'AutoModeratorRevertTalkPageMessageEditSummary' => "edit summary",
			'AutoModeratorFalsePositivePageTitle' => "",
			'AutoModeratorWikiId' => "en"
		] );
		$mockRevisionStore = $this->createMock( RevisionStore::class );
		$logger = $this->createMock( LoggerInterface::class );

		$userGroupManager = $this->createMock( UserGroupManager::class );
		$autoModeratorUser = Util::getAutoModeratorUser( $config, $userGroupManager );
		$titleFactory = $this->getServiceContainer()->getTitleFactory();

		$talkPageMessageSender = new TalkPageMessageSender( $mockRevisionStore, $config,
			$jobQueueGroup, $titleFactory );
		$talkPageMessageSender->insertAutoModeratorSendRevertTalkPageMsgJob(
			$title,
			1,
			2,
			$autoModeratorUser,
			$logger
		);

		$this->assertFalse( $jobQueueGroup->get( 'AutoModeratorSendRevertTalkPageMsgJob' )->pop() );
	}

	/**
	 * @covers AutoModerator\TalkPageMessageSender::insertAutoModeratorSendRevertTalkPageMsgJob
	 */
	public function testTalkPageMessageSenderNotQueuedRevNull() {
		$title = $this->createMock( Title::class );
		$revId = 1;
		$mockRevStore = $this->createMock( RevisionStore::class );
		$mockRevStore->method( 'getRevisionById' )->willReturn( null );
		$jobQueueGroup = $this->getServiceContainer()->getJobQueueGroup();
		$jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->delete();
		$config = new HashConfig( [
			'AutoModeratorEnableRevisionCheck' => true,
			'AutoModeratorUsername' => 'AutoModerator',
			'AutoModeratorRevertTalkPageMessageEnabled' => true,
			'AutoModeratorRevertTalkPageMessageHeading' => "heading",
			'AutoModeratorRevertTalkPageMessageEditSummary' => "edit summary",
			'AutoModeratorFalsePositivePageTitle' => "",
			'AutoModeratorWikiId' => "en"
		] );
		$mockRevisionStore = $this->createMock( RevisionStore::class );
		$logger = $this->createMock( LoggerInterface::class );

		$userGroupManager = $this->createMock( UserGroupManager::class );
		$autoModeratorUser = Util::getAutoModeratorUser( $config, $userGroupManager );
		$titleFactory = $this->getServiceContainer()->getTitleFactory();

		$talkPageMessageSender = new TalkPageMessageSender( $mockRevisionStore, $config,
			$jobQueueGroup, $titleFactory );
		$talkPageMessageSender->insertAutoModeratorSendRevertTalkPageMsgJob( $title, $revId, 2,
			$autoModeratorUser, $logger );

		$this->assertFalse( $jobQueueGroup->get( 'AutoModeratorSendRevertTalkPageMsgJob' )->pop() );
	}

	/**
	 * @covers AutoModerator\TalkPageMessageSender::insertAutoModeratorSendRevertTalkPageMsgJob
	 */
	public function testTalkPageMessageSenderNotQueuedParentRevIdNull() {
		$title = $this->createMock( Title::class );
		$revId = 1;
		$mockRevStore = $this->createMock( RevisionStore::class );
		$mockRevStore->method( 'getRevisionById' )->willReturn( $revId );
		$mockRevRecord = $this->createMock( RevisionRecord::class );
		$mockRevRecord->method( 'getParentId' )->willReturn( null );
		$jobQueueGroup = $this->getServiceContainer()->getJobQueueGroup();
		$jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->delete();
		$config = new HashConfig( [
			'AutoModeratorEnableRevisionCheck' => true,
			'AutoModeratorUsername' => 'AutoModerator',
			'AutoModeratorRevertTalkPageMessageEnabled' => true,
			'AutoModeratorRevertTalkPageMessageHeading' => "heading",
			'AutoModeratorRevertTalkPageMessageEditSummary' => "edit summary",
			'AutoModeratorFalsePositivePageTitle' => "",
			'AutoModeratorWikiId' => "en"
		] );
		$mockRevisionStore = $this->createMock( RevisionStore::class );
		$logger = $this->createMock( LoggerInterface::class );

		$userGroupManager = $this->createMock( UserGroupManager::class );
		$autoModeratorUser = Util::getAutoModeratorUser( $config, $userGroupManager );
		$titleFactory = $this->getServiceContainer()->getTitleFactory();

		$talkPageMessageSender = new TalkPageMessageSender( $mockRevisionStore, $config,
			$jobQueueGroup, $titleFactory );
		$talkPageMessageSender->insertAutoModeratorSendRevertTalkPageMsgJob( $title, $revId, 2,
			$autoModeratorUser, $logger );

		$this->assertFalse( $jobQueueGroup->get( 'AutoModeratorSendRevertTalkPageMsgJob' )->pop() );
	}

	/**
	 * @covers AutoModerator\TalkPageMessageSender::insertAutoModeratorSendRevertTalkPageMsgJob
	 */
	public function testTalkPageMessageSenderNotQueuedDiscussionToolsNotInstalled() {
		$mockExtensionRegistry = $this->createMock( ExtensionRegistry::class );
		$mockExtensionRegistry->method( 'isLoaded' )->willReturn( false );
		$title = $this->createMock( Title::class );
		$revId = 1;
		$mockRevStore = $this->createMock( RevisionStore::class );
		$mockRevStore->method( 'getRevisionById' )->willReturn( $revId );
		$mockRevRecord = $this->createMock( RevisionRecord::class );
		$mockRevRecord->method( 'getParentId' )->willReturn( 989 );
		$jobQueueGroup = $this->getServiceContainer()->getJobQueueGroup();
		$jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->delete();
		$config = new HashConfig( [
			'AutoModeratorEnableRevisionCheck' => true,
			'AutoModeratorUsername' => 'AutoModerator',
			'AutoModeratorRevertTalkPageMessageEnabled' => true,
			'AutoModeratorRevertTalkPageMessageHeading' => "heading",
			'AutoModeratorRevertTalkPageMessageEditSummary' => "edit summary",
			'AutoModeratorFalsePositivePageTitle' => "",
			'AutoModeratorWikiId' => "en"
		] );
		$mockRevisionStore = $this->createMock( RevisionStore::class );
		$logger = $this->createMock( LoggerInterface::class );

		$userGroupManager = $this->createMock( UserGroupManager::class );
		$autoModeratorUser = Util::getAutoModeratorUser( $config, $userGroupManager );
		$titleFactory = $this->getServiceContainer()->getTitleFactory();

		$talkPageMessageSender = new TalkPageMessageSender( $mockRevisionStore, $config,
			$jobQueueGroup, $titleFactory );
		$talkPageMessageSender->insertAutoModeratorSendRevertTalkPageMsgJob( $title, $revId, 2,
			$autoModeratorUser, $logger );

		$this->assertFalse( $jobQueueGroup->get( 'AutoModeratorSendRevertTalkPageMsgJob' )->pop() );
	}

	/**
	 * @covers AutoModerator\TalkPageMessageSender::insertAutoModeratorSendRevertTalkPageMsgJob
	 */
	public function testTalkPageMessageSenderQueued() {
		$title = $this->createMock( Title::class );
		$revId = 94;
		$mockRevisionStore = $this->createMock( RevisionStore::class );
		$mockRevRecord = $this->createMock( RevisionRecord::class );
		$mockRevisionStore->method( 'getRevisionById' )->willReturn( $mockRevRecord );
		$mockRevRecord->method( 'getParentId' )->willReturn( 93 );
		$jobQueueGroup = $this->getServiceContainer()->getJobQueueGroup();
		$jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->delete();
		$config = new HashConfig( [
			'AutoModeratorEnableRevisionCheck' => true,
			'AutoModeratorUsername' => 'AutoModerator',
			'AutoModeratorRevertTalkPageMessageEnabled' => true,
			'AutoModeratorRevertTalkPageMessageHeading' => "heading",
			'AutoModeratorRevertTalkPageMessageEditSummary' => "edit summary",
			'AutoModeratorFalsePositivePageTitle' => "",
			'AutoModeratorMultilingualConfigEnableMultilingual' => false,
			'AutoModeratorWikiId' => "en",
			'TranslateNumerals' => false,
			'AutoModeratorMultiLingualRevertRisk' => false
		] );
		$logger = $this->createMock( LoggerInterface::class );

		$userGroupManager = $this->createMock( UserGroupManager::class );
		$autoModeratorUser = Util::getAutoModeratorUser( $config, $userGroupManager );
		$titleFactory = $this->createMock( TitleFactory::class );
		$titleFalsePositive = $this->createMock( Title::class );
		$titleFalsePositive->method( 'getFullURL' )->willReturn( 'User:AutoModerator/False' );
		$titleFactory->method( 'newFromText' )->willReturn( $titleFalsePositive );

		$talkPageMessageSender = new TalkPageMessageSender( $mockRevisionStore, $config,
			$jobQueueGroup, $titleFactory );
		$talkPageMessageSender->insertAutoModeratorSendRevertTalkPageMsgJob( $title, $revId, 2,
			$autoModeratorUser, $logger );

		$language = $this->getServiceContainer()->getContentLanguage();
		$timestamp = new ConvertibleTimestamp();
		$year = $timestamp->format( 'Y' );
		$month = $language->getMonthName( (int)$timestamp->format( 'n' ) );

		$actual = $jobQueueGroup->get( 'AutoModeratorSendRevertTalkPageMsgJob' )->pop()->getParams();
		$actual['requestId'] = 99;
		$expected = [
			'revId' => 94,
			'rollbackRevId' => 2,
			'autoModeratorUserId' => 1,
			'autoModeratorUserName' => 'AutoModerator',
			'talkPageMessageHeader' => $month . ' ' . $year . ': AutoModerator reverted your edit',
			'talkPageMessageEditSummary' => 'Notice of automated revert on [[]]',
			'falsePositiveReportPageTitle' => 'User:AutoModerator/False?action=edit&section=new&' .
				'nosummary=true&preload=:/Preload&preloadparams%5B%5D=94&preloadparams%5B%5D=',
			'namespace' => 0,
			'title' => '',
			'requestId' => 99

		];

		$this->assertEquals( $expected, $actual );
	}

}
