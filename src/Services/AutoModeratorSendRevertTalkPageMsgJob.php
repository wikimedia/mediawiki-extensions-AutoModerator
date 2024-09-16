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
	 * @var int
	 */
	private int $parentRevId;

	/**
	 * @var ?string
	 */
	private ?string $pageTitle;

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
	private string $falsePositiveReportPageTitle;

	/**
	 * @var string
	 */
	private string $wikiId;

	private const NOT_WIKI_TEXT_ERROR_MESSAGE = 'Failed to send AutoModerator revert talk page message '
		. 'due to content model not being wikitext the current content model is: ';

	private const NO_USER_TALK_PAGE_ERROR_MESSAGE = 'Failed to retrieve user talk page title '
		. 'for sending AutoModerator revert talk page message.';

	private const NO_PARENT_REVISION_FOUND = 'Failed to retrieve reverted revision from revision store.';

	private const NO_CONTENT_TALK_PAGE_ERROR_MESSAGE = 'Failed to create AutoModerator revert message '
		. 'content for talk page';

	private const CREATE_TALK_PAGE_ERROR_MESSAGE = 'Failed to create message for sending AutoModerator revert '
		. 'talk page message.';

	/**
	 * @param Title $title
	 * @param array $params
	 *    - 'wikiPageId': (int)
	 *    - 'revId': (int)
	 *    - 'parentRevId': (int)
	 *    - 'autoModeratorUserId': (int)
	 *    - 'autoModeratorUserName': (string)
	 *    - 'talkPageMessageHeader': (string)
	 *    - 'talkPageMessageEditSummary': (string)
	 *    - 'falsePositiveReportPageTitle': (string)
	 *    - 'wikiId': (string)
	 */
	public function __construct( Title $title, array $params ) {
		parent::__construct( 'AutoModeratorSendRevertTalkPageMsgJob', $title, $params );
		$this->pageTitle = $title;
		$this->wikiPageId = $params['wikiPageId'];
		$this->revId = $params['revId'];
		$this->parentRevId = $params['parentRevId'];
		$this->autoModeratorUserId = $params['autoModeratorUserId'];
		$this->autoModeratorUserName = $params['autoModeratorUserName'];
		$this->talkPageMessageHeader = $params['talkPageMessageHeader'];
		$this->talkPageMessageEditSummary = $params['talkPageMessageEditSummary'];
		$this->falsePositiveReportPageTitle = $params['falsePositiveReportPageTitle'];
		$this->wikiId = $params['wikiId'];
	}

	public function run(): bool {
		$logger = LoggerFactory::getInstance( 'AutoModerator' );
		try {
			$services = MediaWikiServices::getInstance();
			$userFactory = $services->getUserFactory();
			$revisionStore = $services->getRevisionStore();
			$parentRevision = $revisionStore->getRevisionById( $this->parentRevId );
			if ( !$parentRevision ) {
				$this->setLastError( self::NO_PARENT_REVISION_FOUND );
				$this->setAllowRetries( false );
				return false;
			}
			$userTalkPageTitle = $services->getTitleFactory()->makeTitleSafe(
				NS_USER_TALK,
				$parentRevision->getUser()->getName()
			);
			if ( !$userTalkPageTitle ) {
				$this->setLastError( self::NO_USER_TALK_PAGE_ERROR_MESSAGE );
				$this->setAllowRetries( false );
				return false;
			}
			$autoModeratorUser = $userFactory->newFromAnyId(
				$this->params['autoModeratorUserId'],
				$this->params['autoModeratorUserName']
			);
			$userTalkPage = $services->getWikiPageFactory()->newFromTitle( $userTalkPageTitle );
			$currentContentModel = $userTalkPage->getContentModel();
			if ( $currentContentModel !== CONTENT_MODEL_WIKITEXT ) {
				$logger->error( self::NOT_WIKI_TEXT_ERROR_MESSAGE . $currentContentModel );
				$this->setLastError( self::NOT_WIKI_TEXT_ERROR_MESSAGE . $currentContentModel );
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
					$this->falsePositiveReportPageTitle )->plain(),
				$userTalkPageTitle,
				$currentContentModel );
			if ( !$updatedContent ) {
				$logger->error( self::NO_CONTENT_TALK_PAGE_ERROR_MESSAGE );
				$this->setLastError( self::NO_CONTENT_TALK_PAGE_ERROR_MESSAGE );
				return false;
			}
			$userTalkPage
				->newPageUpdater( $autoModeratorUser )
				->setContent( SlotRecord::MAIN, $updatedContent )
				->saveRevision( CommentStoreComment::newUnsavedComment( $this->talkPageMessageEditSummary ),
			  $userTalkPage->exists() ? EDIT_UPDATE : EDIT_NEW );
		} catch ( RuntimeException $e ) {
			$this->setLastError( self::CREATE_TALK_PAGE_ERROR_MESSAGE );
			$logger->error( self::CREATE_TALK_PAGE_ERROR_MESSAGE );
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
	 * @param string $contentModel
	 * @return Content|null
	 */
	private function createTalkPageMessageContent(
		?Content $currentContent,
		string $headerRawMessage,
		string $messageContent,
		Title $userTalkPageTitle,
		string $contentModel
	): ?Content {
		$newLine = "\n";
		$signature = " -- ~~~~";
		if ( $currentContent ) {
			return $currentContent->getContentHandler()->makeContent(
				$currentContent->getWikitextForTransclusion() . $newLine . $headerRawMessage . $newLine . $newLine
				. $messageContent . $signature,
				$userTalkPageTitle,
				$contentModel
			);
		} else {
			$contentHandler = MediaWikiServices::getInstance()
				->getContentHandlerFactory()
				->getContentHandler( $contentModel );
			return $contentHandler->makeContent(
				$headerRawMessage . $newLine . $newLine . $messageContent . $signature,
				$userTalkPageTitle,
				$contentModel
			);
		}
	}
}
