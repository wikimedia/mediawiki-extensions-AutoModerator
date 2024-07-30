<?php

namespace AutoModerator\Tests;

use AutoModerator\Services\AutoModeratorSendRevertTalkPageMsgJob;
use AutoModerator\Util;
use MediaWiki\MediaWikiServices;
use MediaWikiIntegrationTestCase;

/**
 * @group AutoModerator
 * @group Database
 * @covers AutoModerator\Services\AutoModeratorSendRevertTalkPageMsgJob
 */
class AutoModeratorSendRevertTalkPageMsgJobTest extends MediaWikiIntegrationTestCase {

	/**
	 * @return array
	 */
	private function createTestPage(): array {
		$wikiPage = $this->insertPage( 'TestJob', 'Test text' );
		$user = $this->getTestUser()->getUserIdentity();
		$this->editPage( $this->getExistingTestPage( $wikiPage['title'] ), 'Content' );
		$title = $wikiPage['title'];
		return [ $wikiPage, $user, $title ];
	}

	/**
	 * @param string $name
	 * @return array
	 */
	private function createTestWikiTalkPage( string $name ): array {
		$titleFactory = MediaWikiServices::getInstance()->getTitleFactory();
		return $this->insertPage( $titleFactory->makeTitleSafe(
			NS_USER_TALK,
			$name
		) );
	}

	/**
	 * @param string $name
	 * @return array
	 */
	private function createNonWikiTextUserTalkPage( string $name, string $text ): array {
		$titleFactory = MediaWikiServices::getInstance()->getTitleFactory();
		$title = $titleFactory->makeTitleSafe(
			NS_USER_TALK,
			$name
		);
		$title->setContentModel( CONTENT_MODEL_JSON );
		return $this->insertPage( $title, $text );
	}

	/**
	 * @covers AutoModerator\Services\AutoModeratorSendRevertTalkPageMsgJob::run
	 * @group Database
	 */
	public function testRunSuccessSendsTalkPageMessage() {
		[ $wikiPage, $user, $title ] = $this->createTestPage();
		$wikiTalkPageCreated = $this->createTestWikiTalkPage( $user->getName(), "some random text" );
		$expectedFalsePositiveReportPage = "false-positive-page";
		$mediaWikiServices = MediaWikiServices::getInstance();
		$this->overrideConfigValue( 'AutoModeratorUsername', 'AutoModerator' );
		$autoModeratorUser = Util::getAutoModeratorUser( $mediaWikiServices->getMainConfig(),
			$mediaWikiServices->getUserGroupManager() );
		$job = new AutoModeratorSendRevertTalkPageMsgJob( $title,
			[
				'wikiPageId' => $wikiPage['id'],
				'revId' => "",
				'parentRevId' => 2,
				'autoModeratorUserId' => $autoModeratorUser->getId(),
				'autoModeratorUserName' => $autoModeratorUser->getName(),
				'talkPageMessageHeader' => "header",
				'talkPageMessageEditSummary' => "edit summary",
				'falsePositiveReportPage' => $expectedFalsePositiveReportPage,
				'wikiId' => "enwiki",
			]
		);
		$success = $job->run();
		$this->assertTrue( $success );

		$currentTalkPageContent = $this->getExistingTestPage( $wikiTalkPageCreated['title'] )
			->getContent()
			->getWikitextForTransclusion();
		$expectedTalkPageHeaderMessageContent = "Hello! I am [[User:AutoModerator|AutoModerator]]";

		$this->assertStringContainsString( $expectedTalkPageHeaderMessageContent, $currentTalkPageContent );
		$this->assertStringContainsString( $title, $currentTalkPageContent );
		$this->assertStringContainsString( $expectedFalsePositiveReportPage, $currentTalkPageContent );
	}

	/**
	 * @covers AutoModerator\Services\AutoModeratorSendRevertTalkPageMsgJob::run
	 * @group Database
	 */
	public function testRunFailureWhenNoParentRevision() {
		[ $wikiPage, $user, $title ] = $this->createTestPage();
		$mediaWikiServices = MediaWikiServices::getInstance();
		$expectedFalsePositiveReportPage = "false-positive-page";
		$autoModeratorUser = Util::getAutoModeratorUser( $mediaWikiServices->getMainConfig(),
			$mediaWikiServices->getUserGroupManager() );

		$job = new AutoModeratorSendRevertTalkPageMsgJob( $title,
			[
				'wikiPageId' => $wikiPage['id'],
				'revId' => "",
				'parentRevId' => 1000,
				'autoModeratorUserId' => $autoModeratorUser->getId(),
				'autoModeratorUserName' => $autoModeratorUser->getName(),
				'talkPageMessageHeader' => "header",
				'talkPageMessageEditSummary' => "edit summary",
				'falsePositiveReportPage' => $expectedFalsePositiveReportPage,
				'wikiId' => "enwiki",
			]
		);
		$success = $job->run();
		$this->assertFalse( $success );
		$this->assertEquals( "Failed to retrieve reverted revision from revision store.",
			$job->getLastError() );
	}

	/**
	 * @covers AutoModerator\Services\AutoModeratorSendRevertTalkPageMsgJob::run
	 * @group Database
	 */
	public function testRunFailureUserTalkPageNotWikiText() {
		[ $wikiPage, $user, $title ] = $this->createTestPage();
		$this->createNonWikiTextUserTalkPage( $user->getName(), "{}" );
		$mediaWikiServices = MediaWikiServices::getInstance();
		$autoModeratorUser = Util::getAutoModeratorUser( $mediaWikiServices->getMainConfig(),
			$mediaWikiServices->getUserGroupManager() );
		$expectedFalsePositiveReportPage = "false-positive-page";

		$job = new AutoModeratorSendRevertTalkPageMsgJob( $title,
			[
				'wikiPageId' => $wikiPage['id'],
				'revId' => "",
				'parentRevId' => 2,
				'autoModeratorUserId' => $autoModeratorUser->getId(),
				'autoModeratorUserName' => $autoModeratorUser->getName(),
				'talkPageMessageHeader' => "header",
				'talkPageMessageEditSummary' => "edit summary",
				'falsePositiveReportPage' => $expectedFalsePositiveReportPage,
				'wikiId' => "enwiki",
			]
		);
		$expectedContentModel = CONTENT_MODEL_JSON;
		$success = $job->run();
		$this->assertFalse( $success );
		$this->assertEquals(
			"Failed to send AutoModerator revert talk page message due to content model not being wikitext "
			. "the current content model is: $expectedContentModel",
			$job->getLastError()
		);
	}
}
