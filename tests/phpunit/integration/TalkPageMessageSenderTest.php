<?php

namespace AutoModerator\Tests;

use AutoModerator\TalkPageMessageSender;
use AutoModerator\Util;
use MediaWiki\Config\HashConfig;
use MediaWiki\Language\Language;
use MediaWiki\MainConfigNames;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\User;
use MediaWiki\User\UserGroupManager;
use Psr\Log\LoggerInterface;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group AutoModerator
 * @group Database
 * @coversDefaultClass \AutoModerator\TalkPageMessageSender
 */
class TalkPageMessageSenderTest extends \MediaWikiIntegrationTestCase {

	/**
	 * @covers ::insertAutoModeratorSendRevertTalkPageMsgJob
	 */
	public function testTalkPageMessageSenderNotQueuedRevNull() {
		$this->markTestSkippedIfExtensionNotLoaded( 'DiscussionTools' );

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

		$talkPageMessageSender = new TalkPageMessageSender(
			$this->createMock( RevisionStore::class ),
			$config,
			$jobQueueGroup,
			$this->createNoopMock( TitleFactory::class ),
			$this->createNoopMock( Language::class ),
			$this->createMock( LoggerInterface::class )
		);
		$talkPageMessageSender->insertAutoModeratorSendRevertTalkPageMsgJob(
			$this->createMock( Title::class ),
			1,
			2,
			$this->createMock( User::class )
		);

		$this->assertFalse( $jobQueueGroup->get( 'AutoModeratorSendRevertTalkPageMsgJob' )->pop() );
	}

	/**
	 * @covers ::insertAutoModeratorSendRevertTalkPageMsgJob
	 */
	public function testTalkPageMessageSenderQueued() {
		$this->markTestSkippedIfExtensionNotLoaded( 'DiscussionTools' );

		$revId = 94;
		$mockRevRecord = $this->createMock( RevisionRecord::class );
		$mockRevisionStore = $this->createMock( RevisionStore::class );
		$mockRevisionStore->method( 'getRevisionById' )->with( $revId )->willReturn( $mockRevRecord );
		$jobQueueGroup = $this->getServiceContainer()->getJobQueueGroup();
		$jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->delete();
		$config = new HashConfig( [
			'AutoModeratorEnableRevisionCheck' => true,
			'AutoModeratorUsername' => 'AutoModerator',
			'AutoModeratorRevertTalkPageMessageEnabled' => true,
			'AutoModeratorRevertTalkPageMessageHeading' => "heading",
			'AutoModeratorRevertTalkPageMessageEditSummary' => "edit summary",
			'AutoModeratorFalsePositivePageTitle' => '',
			'AutoModeratorMultilingualConfigEnableMultilingual' => false,
			'AutoModeratorWikiId' => "en",
			'TranslateNumerals' => false,
			'AutoModeratorMultiLingualRevertRisk' => false
		] );
		$this->overrideConfigValue( MainConfigNames::LanguageCode, 'en' );
		ConvertibleTimestamp::setFakeTime( '2026-06-01T12:34:56' );

		$userGroupManager = $this->createMock( UserGroupManager::class );
		$autoModeratorUser = Util::getAutoModeratorUser( $config, $userGroupManager );
		$titleFalsePositive = $this->createMock( Title::class );
		$titleFalsePositive->method( 'getFullURL' )->willReturn( 'User:AutoModerator/False' );
		$titleFactory = $this->createMock( TitleFactory::class );
		$titleFactory->method( 'newFromText' )->willReturn( $titleFalsePositive );

		$talkPageMessageSender = new TalkPageMessageSender(
			$mockRevisionStore,
			$config,
			$jobQueueGroup,
			$titleFactory,
			$this->getServiceContainer()->getContentLanguage(),
			$this->createMock( LoggerInterface::class )
		);
		$talkPageMessageSender->insertAutoModeratorSendRevertTalkPageMsgJob(
			$this->createMock( Title::class ),
			$revId,
			100,
			$autoModeratorUser
		);

		$actual = $jobQueueGroup->get( 'AutoModeratorSendRevertTalkPageMsgJob' )->pop()->getParams();
		unset( $actual['requestId'] );
		$expected = [
			'revId' => 94,
			'rollbackRevId' => 100,
			'autoModeratorUserId' => 1,
			'autoModeratorUserName' => 'AutoModerator',
			'talkPageMessageHeader' => 'June 2026: AutoModerator reverted your edit',
			'talkPageMessageEditSummary' => 'Notice of automated revert on [[]]',
			'falsePositiveReportPageTitle' => 'User:AutoModerator/False?action=edit&section=new&' .
				'nosummary=true&preload=:/Preload&preloadparams%5B%5D=94&preloadparams%5B%5D=',
			'namespace' => 0,
			'title' => '',
		];

		$this->assertSame( $expected, $actual );
	}

}
