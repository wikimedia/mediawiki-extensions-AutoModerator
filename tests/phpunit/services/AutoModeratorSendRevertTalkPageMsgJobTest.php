<?php

namespace AutoModerator\Tests;

use AutoModerator\Services\AutoModeratorSendRevertTalkPageMsgJob;
use AutoModerator\Util;
use MediaWiki\Api\ApiMain;
use MediaWiki\Api\ApiQueryTokens;
use MediaWiki\Api\ApiUsageException;
use MediaWiki\Context\DerivativeContext;
use MediaWiki\Context\RequestContext;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Session\SessionManager;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;
use MockHttpTrait;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group AutoModerator
 * @group Database
 * @covers AutoModerator\Services\AutoModeratorSendRevertTalkPageMsgJob
 */
class AutoModeratorSendRevertTalkPageMsgJobTest extends MediaWikiIntegrationTestCase {
	use MockHttpTrait;

	/**
	 * @return array
	 */
	private function createTestPage(): array {
		$wikiPage = $this->insertPage( 'TestJob', 'Test text' );
		$user = $this->getTestUser()->getUserIdentity();
		$this->editPage( $this->getExistingTestPage( $wikiPage['title'] ), 'Content' );
		$title = $wikiPage['title'];
		return [ $user, $title ];
	}

	/**
	 * @param string $name
	 * @return array
	 */
	private function createTestWikiTalkPage( string $name ): array {
		$talkPageTitle = $this->getServiceContainer()->getTitleFactory()->makeTitleSafe(
			NS_USER_TALK,
			$name
		);
		return $this->insertPage( $talkPageTitle, $name );
	}

	/**
	 * @param User $autoModeratorUser
	 * @param array $wikiTalkPageCreated
	 * @return array
	 */
	private function createPageInfoResponse( User $autoModeratorUser, array $wikiTalkPageCreated ) {
		$session = SessionManager::singleton()->getEmptySession();
		$session->setUser( $autoModeratorUser );
		$context = new DerivativeContext( RequestContext::getMain() );
		$context->setUser( $autoModeratorUser );
		$queryParams = [
			"action" => "discussiontoolspageinfo",
			"format" => "json",
			"page" => $wikiTalkPageCreated['title'],
			"prop" => "threaditemshtml"
		];

		$context->setRequest( new FauxRequest( $queryParams, true, $session ) );

		$api = new ApiMain( $context, true );
		$api->execute();

		return $api->getResult()->getResultData();
	}

	/**
	 * @param User $autoModeratorUser
	 * @param array $wikiTalkPageCreated
	 * @param string $header
	 * @return array
	 */
	private function createFindCommentResponse( User $autoModeratorUser, array $wikiTalkPageCreated, string $header ) {
		$session = SessionManager::singleton()->getEmptySession();
		$session->setUser( $autoModeratorUser );
		$context = new DerivativeContext( RequestContext::getMain() );
		$context->setUser( $autoModeratorUser );
		$queryParams = [
			"action" => "discussiontoolsfindcomment",
			"format" => "json",
			"heading" => $header,
			"page" => $wikiTalkPageCreated['title']
		];

		$context->setRequest( new FauxRequest( $queryParams, true, $session ) );

		$api = new ApiMain( $context, true );
		try {
			$api->execute();
		} catch ( ApiUsageException $th ) {
			return [];
		}

		return $api->getResult()->getResultData();
	}

	/**
	 * @param User $autoModeratorUser
	 * @param array $wikiTalkPageCreated
	 * @param string $header
	 * @return array
	 */
	private function createAddTopicResponse( User $autoModeratorUser, array $wikiTalkPageCreated, string $header ) {
		$session = SessionManager::singleton()->getEmptySession();
		$session->setUser( $autoModeratorUser );
		$context = new DerivativeContext( RequestContext::getMain() );
		$context->setUser( $autoModeratorUser );
		$queryParams = [
			"action" => "discussiontoolsedit",
			"format" => "json",
			"paction" => "addtopic",
			"page" => $wikiTalkPageCreated['title'],
			"wikitext" => "Talk page message",
			"sectiontitle" => $header,
			"summary" => "Summary",
			"token" => ApiQueryTokens::getToken(
				$autoModeratorUser,
				$session,
				ApiQueryTokens::getTokenTypeSalts()['csrf']
			)
		];

		$context->setRequest( new FauxRequest( $queryParams, true, $session ) );

		$api = new ApiMain( $context, true );
		try {
			$api->execute();
		} catch ( ApiUsageException $th ) {
			return [];
		}

		return $api->getResult()->getResultData();
	}

	/**
	 * @param User $autoModeratorUser
	 * @param array $wikiTalkPageCreated
	 * @param string $commentId
	 * @param string $followUpComment
	 * @return array
	 */
	private function createAddFollowUpCommentResponse( User $autoModeratorUser, array $wikiTalkPageCreated,
		string $commentId, string $followUpComment ) {
		$session = SessionManager::singleton()->getEmptySession();
		$session->setUser( $autoModeratorUser );
		$context = new DerivativeContext( RequestContext::getMain() );
		$context->setUser( $autoModeratorUser );
		$queryParams = [
			"action" => "discussiontoolsedit",
			"format" => "json",
			"paction" => "addcomment",
			"page" => $wikiTalkPageCreated['title'],
			"commentid" => $commentId,
			"wikitext" => $followUpComment,
			"token" => ApiQueryTokens::getToken(
				$autoModeratorUser,
				$session,
				ApiQueryTokens::getTokenTypeSalts()['csrf']
			)
		];

		$context->setRequest( new FauxRequest( $queryParams, true, $session ) );

		$api = new ApiMain( $context, true );
		try {
			$api->execute();
		} catch ( ApiUsageException $th ) {
			return [];
		}

		return $api->getResult()->getResultData();
	}

	/**
	 * @covers AutoModerator\Services\AutoModeratorSendRevertTalkPageMsgJob::run
	 * @covers AutoModerator\ApiClient::findComment
	 * @covers AutoModerator\ApiClient::checkCommentRedirects
	 * @covers AutoModerator\ApiClient::getUserTalkPageInfo
	 * @covers AutoModerator\ApiClient::addTopic
	 * @covers AutoModerator\Util::initializeApiClient
	 * @group Database
	 */
	public function testRunSuccessSendsTalkPageMessageAddTopic() {
		[ $user, $title ] = $this->createTestPage();
		$wikiTalkPageCreated = $this->createTestWikiTalkPage( $user->getName() );
		$expectedFalsePositiveReportPage = "false-positive-page";
		$mediaWikiServices = $this->getServiceContainer();
		$this->overrideConfigValue( 'AutoModeratorUsername', 'AutoModerator' );
		$this->overrideConfigValue( 'AutoModeratorHelpPageLink', 'Special:Help' );
		$autoModeratorUser = Util::getAutoModeratorUser( $mediaWikiServices->getMainConfig(),
			$mediaWikiServices->getUserGroupManager() );

		$header = "header";

		$this->createPageInfoResponse( $autoModeratorUser, $wikiTalkPageCreated );
		$this->createFindCommentResponse( $autoModeratorUser, $wikiTalkPageCreated, $header );
		$revId = $this->getServiceContainer()
			->getWikiPageFactory()
			->newFromTitle( $title )
			->getRevisionRecord()
			->getId();
		$job = new AutoModeratorSendRevertTalkPageMsgJob( $title,
			[
				'revId' => $revId,
				'rollbackRevId' => 2,
				'autoModeratorUserId' => $autoModeratorUser->getId(),
				'autoModeratorUserName' => $autoModeratorUser->getName(),
				'talkPageMessageHeader' => $header,
				'talkPageMessageEditSummary' => "edit summary",
				'falsePositiveReportPageTitle' => $expectedFalsePositiveReportPage,
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
	 * @covers AutoModerator\ApiClient::findComment
	 * @covers AutoModerator\ApiClient::checkCommentRedirects
	 * @covers AutoModerator\ApiClient::getUserTalkPageInfo
	 * @covers AutoModerator\ApiClient::addFollowUpComment
	 * @covers AutoModerator\Util::initializeApiClient
	 * @group Database
	 */
	public function testRunSuccessSendsTalkPageMessageAddFollowUpComment() {
		[ $user, $title ] = $this->createTestPage();
		$language = $this->getServiceContainer()->getContentLanguage();
		$timestamp = new ConvertibleTimestamp();
		$year = $timestamp->format( 'Y' );
		$month = $language->getMonthName( (int)$timestamp->format( 'n' ) );
		$expectedFalsePositiveReportPage = "false-positive-page";
		$mediaWikiServices = $this->getServiceContainer();
		$this->overrideConfigValue( 'AutoModeratorUsername', 'AutoModerator' );
		$autoModeratorUser = Util::getAutoModeratorUser( $mediaWikiServices->getMainConfig(),
			$mediaWikiServices->getUserGroupManager() );
		// Create the talk page with the AutoModerator Talk Page message
		$talkPageMessageHeader = "==" . $month . " " . $year . ":" . $autoModeratorUser->getName() .
			" reverted your edit==";
		$headerNoEqualsSymbol = trim( str_replace( "==", "", $talkPageMessageHeader ) );
		$headerWithoutSpaces = str_replace( " ", "_", $headerNoEqualsSymbol );
		$wikiTalkPageCreated = $this->createTestWikiTalkPage( $user->getName() );

		// Adding a Topic with AutoModerator's message so the job adds the follow-up message
		$this->createAddTopicResponse( $autoModeratorUser, $wikiTalkPageCreated,
			$headerNoEqualsSymbol );
		$apiPageInfoResponse = $this->createPageInfoResponse( $autoModeratorUser, $wikiTalkPageCreated );
		$this->createFindCommentResponse( $autoModeratorUser, $wikiTalkPageCreated,
			$headerWithoutSpaces );
		$commentId = $apiPageInfoResponse["discussiontoolspageinfo"]["threaditemshtml"][0]["id"];
		$this->createAddFollowUpCommentResponse( $autoModeratorUser, $wikiTalkPageCreated,
			$commentId, "I also reverted one of your [[Special:Diff/42|recent edits]] to [[" .
			$title . "]] because it seemed unconstructive." );
		$revId = $this->getServiceContainer()
			->getWikiPageFactory()
			->newFromTitle( $title )
			->getRevisionRecord()
			->getId();
		$job = new AutoModeratorSendRevertTalkPageMsgJob( $title,
			[
				'revId' => $revId,
				'rollbackRevId' => 2,
				'autoModeratorUserId' => $autoModeratorUser->getId(),
				'autoModeratorUserName' => $autoModeratorUser->getName(),
				'talkPageMessageHeader' => $talkPageMessageHeader,
				'talkPageMessageEditSummary' => "edit summary",
				'falsePositiveReportPageTitle' => $expectedFalsePositiveReportPage,
			]
		);
		$success = $job->run();
		$this->assertTrue( $success );

		$currentTalkPageContent = $this->getExistingTestPage( $wikiTalkPageCreated['title'] )
			->getContent()
			->getWikitextForTransclusion();
		$expectedTalkPageFollowUpComment = "I also reverted one of your [[Special:Diff/$revId|recent edits]] to [[" .
			$title . "]] because it seemed unconstructive.";

		$this->assertStringContainsString( $expectedTalkPageFollowUpComment, $currentTalkPageContent );
		$this->assertStringContainsString( $title, $currentTalkPageContent );
	}

	/**
	 * @covers AutoModerator\Services\AutoModeratorSendRevertTalkPageMsgJob::run
	 * @group Database
	 */
	public function testRunFailureWhenNoRevision() {
		[ $user, $title ] = $this->createTestPage();
		$mediaWikiServices = $this->getServiceContainer();
		$expectedFalsePositiveReportPage = "false-positive-page";
		$autoModeratorUser = Util::getAutoModeratorUser( $mediaWikiServices->getMainConfig(),
			$mediaWikiServices->getUserGroupManager() );

		$job = new AutoModeratorSendRevertTalkPageMsgJob( $title,
			[
				'revId' => 1000,
				'rollbackRevId' => 1001,
				'autoModeratorUserId' => $autoModeratorUser->getId(),
				'autoModeratorUserName' => $autoModeratorUser->getName(),
				'talkPageMessageHeader' => "header",
				'talkPageMessageEditSummary' => "edit summary",
				'falsePositiveReportPageTitle' => $expectedFalsePositiveReportPage,
			]
		);
		$success = $job->run();
		$this->assertFalse( $success );
		$this->assertEquals( "Failed to retrieve reverted revision from revision store.",
			$job->getLastError() );
	}
}
