<?php
/**
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http:www.gnu.org/licenses/>.
 */

namespace AutoModerator\Services;

use AutoModerator\Config\AutoModeratorConfigLoaderStaticTrait;
use Content;
use Job;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use RuntimeException;

class AutoModeratorSendRevertTalkPageMsgJob extends Job {

	use AutoModeratorConfigLoaderStaticTrait;

	/**
	 * @var bool
	 */
	private bool $isRetryable = true;

	/**
	 * @var int
	 */
	private $wikiPageId;

	/**
	 * @var int
	 */
	private $revId;

	/**
	 * @var ?string
	 */
	private ?string $pageTitle;

	/**
	 * @var ?Title
	 */
	private ?Title $userTalkPageTitle;

	/**
	 * @var int
	 */
	private int $autoModeratorUserId;

	/**
	 * @var string
	 */
	private string $autoModeratorUserName;

	/**
	 * @var string
	 */
	private string $talkPageMessageHeader;

	/**
	 * @var string
	 */
	private string $talkPageMessageEditSummary;

	/**
	 * @var string
	 */
	private string $falsePositiveReportPage;

	/**
	 * @var string
	 */
	private string $wikiId;

	/**
	 * @var string
	 */
	private string $NOT_WIKI_TEXT_ERROR_MESSAGE = 'Failed to send AutoModerator revert talk page message
	due to content model not being wikitext the current content model is: ';

	/**
	 * @var string
	 */
	private string $NO_USER_TALK_PAGE_ERROR_MESSAGE = "Failed to retrieve user talk page title
			for sending AutoModerator revert talk page message.";

	/**
	 * @var string
	 */
	private string $NO_CONTENT_TALK_PAGE_ERROR_MESSAGE = "Failed to create AutoModerator revert message
	content for talk page.";

	/**
	 * @var string
	 */
	private string $CREATE_TALK_PAGE_ERROR_MESSAGE = "Failed to create message for sending AutoModerator revert
	 talk page message.";

	/**
	 * @param Title $title
	 * @param array $params
	 *    - 'wikiPageId': (int)
	 *    - 'revId': (int)
	 *    - 'autoModeratorUserId': (int)
	 *    - 'autoModeratorUserName': (string)
	 *    - 'userTalkPageTitle': (Title|null)
	 *    - 'talkPageMessageHeader': (string)
	 *    - 'talkPageMessageEditSummary': (string)
	 *    - 'falsePositiveReportPage': (string)
	 *    - 'wikiId': (string)
	 */
	public function __construct( Title $title, array $params ) {
		parent::__construct( 'AutoModeratorSendRevertTalkPageMsgJob', $title, $params );
		$this->pageTitle = $title;
		$this->wikiPageId = $params['wikiPageId'];
		$this->revId = $params['revId'];
		$this->autoModeratorUserId = $params['autoModeratorUserId'];
		$this->autoModeratorUserName = $params['autoModeratorUserName'];
		$this->userTalkPageTitle = $params['userTalkPageTitle'];
		$this->talkPageMessageHeader = $params['talkPageMessageHeader'];
		$this->talkPageMessageEditSummary = $params['talkPageMessageEditSummary'];
		$this->falsePositiveReportPage = $params['falsePositiveReportPage'] ?? "";
		$this->wikiId = $params['wikiId'];
	}

	public function run(): bool {
		$logger = LoggerFactory::getInstance( 'AutoModerator' );
		if ( !$this->userTalkPageTitle ) {
			$this->setLastError( $this->NO_USER_TALK_PAGE_ERROR_MESSAGE );
			$this->setAllowRetries( false );
			return false;
		}
		try {
			$services = MediaWikiServices::getInstance();
			$userFactory = $services->getUserFactory();
			$autoModeratorUser = $userFactory->newFromAnyId(
				$this->params['autoModeratorUserId'],
				$this->params['autoModeratorUserName']
			);
			$userTalkPage = $services->getWikiPageFactory()->newFromTitle( $this->userTalkPageTitle );
			$currentContentModel = $userTalkPage->getContentModel();
			if ( $currentContentModel !== CONTENT_MODEL_WIKITEXT ) {
				$logger->error( $this->NOT_WIKI_TEXT_ERROR_MESSAGE . $currentContentModel );
				$this->setLastError( $this->NOT_WIKI_TEXT_ERROR_MESSAGE . $currentContentModel );
				$this->setAllowRetries( false );
				return false;
			}
			$updatedContent = $this->createTalkPageMessageContent(
				$userTalkPage->getContent(),
				$this->talkPageMessageHeader,
				wfMessage( 'automoderator-wiki-revert-message' )->params(
					$this->autoModeratorUserName,
					$this->revId,
					$this->pageTitle,
					$this->falsePositiveReportPage )->plain(),
				$this->userTalkPageTitle,
				$currentContentModel );
			if ( !$updatedContent ) {
				$logger->error( $this->NO_CONTENT_TALK_PAGE_ERROR_MESSAGE );
				$this->setLastError( $this->NO_CONTENT_TALK_PAGE_ERROR_MESSAGE );
				return false;
			}
			$userTalkPage
				->newPageUpdater( $autoModeratorUser )
				->setContent( SlotRecord::MAIN, $updatedContent )
				->saveRevision( CommentStoreComment::newUnsavedComment( $this->talkPageMessageEditSummary ),
			  $userTalkPage->exists() ? EDIT_UPDATE : EDIT_NEW );
		} catch ( RuntimeException $e ) {
			$this->setLastError( $this->CREATE_TALK_PAGE_ERROR_MESSAGE );
			$logger->error( $this->CREATE_TALK_PAGE_ERROR_MESSAGE );
			return false;
		}
		return true;
	}

	private function setAllowRetries( bool $isRetryable ) {
		$this->isRetryable = $isRetryable;
	}

	/**
	 * @inheritDoc
	 * @codeCoverageIgnore
	 */
	public function allowRetries(): bool {
		return $this->isRetryable;
	}

	/**
	 * @inheritDoc
	 * @codeCoverageIgnore
	 */
	public function ignoreDuplicates(): bool {
		return true;
	}

	/**
	 * @param ?Content $currentContent
	 * @param string $headerRawMessage
	 * @param string $messageContent
	 * @param Title $userTalkPageTitle
	 * @return Content|null
	 */
	private function createTalkPageMessageContent( ?Content $currentContent,
												   string $headerRawMessage,
												   string $messageContent,
												   Title $userTalkPageTitle,
												   string $contentModel ): ?Content {
		if ( $currentContent ) {
			$newLine = "\n";
			return $currentContent->getContentHandler()->makeContent(
				$currentContent->getWikitextForTransclusion() . $newLine . $headerRawMessage . $newLine .
				$messageContent,
				$userTalkPageTitle,
				$contentModel
			);
		} else {
			$contentHandler = MediaWikiServices::getInstance()
				->getContentHandlerFactory()
				->getContentHandler( $contentModel );
			return $contentHandler->makeContent(
				$headerRawMessage . $messageContent,
				$userTalkPageTitle,
				$contentModel
			);
		}
	}
}
