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

use AutoModerator\AutoModeratorServices;
use AutoModerator\Util;
use Job;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use RuntimeException;

class AutoModeratorSendRevertTalkPageMsgJob extends Job {

	/**
	 * @var bool
	 */
	private bool $isRetryable = true;

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

	private const NO_USER_TALK_PAGE_ERROR_MESSAGE = 'Failed to retrieve user talk page title '
		. 'for sending AutoModerator revert talk page message.';

	private const NO_PARENT_REVISION_FOUND = 'Failed to retrieve reverted revision from revision store.';

	/**
	 * @param Title $title
	 * @param array $params
	 *    - 'revId': (int)
	 *    - 'parentRevId': (int)
	 *    - 'autoModeratorUserId': (int)
	 *    - 'autoModeratorUserName': (string)
	 *    - 'talkPageMessageHeader': (string)
	 *    - 'talkPageMessageEditSummary': (string)
	 *    - 'falsePositiveReportPageTitle': (string)
	 */
	public function __construct( Title $title, array $params ) {
		parent::__construct( 'AutoModeratorSendRevertTalkPageMsgJob', $title, $params );
		$this->pageTitle = $title;
		$this->revId = $params['revId'];
		$this->parentRevId = $params['parentRevId'];
		$this->autoModeratorUserId = $params['autoModeratorUserId'];
		$this->autoModeratorUserName = $params['autoModeratorUserName'];
		$this->talkPageMessageHeader = $params['talkPageMessageHeader'];
		$this->talkPageMessageEditSummary = $params['talkPageMessageEditSummary'];
		$this->falsePositiveReportPageTitle = $params['falsePositiveReportPageTitle'];
	}

	public function run(): bool {
		$logger = LoggerFactory::getInstance( 'AutoModerator' );
		try {
			$services = MediaWikiServices::getInstance();
			$autoModeratorServices = AutoModeratorServices::wrap( $services );
			$userFactory = $services->getUserFactory();
			$revisionStore = $services->getRevisionStore();
			$parentRevision = $revisionStore->getRevisionById( $this->parentRevId );
			$wikiConfig = $autoModeratorServices->getAutoModeratorWikiConfig();
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

			$userTalkPage = $services->getWikiPageFactory()->newFromTitle( $userTalkPageTitle );

			$autoModeratorUser = $userFactory->newFromAnyId(
				$this->autoModeratorUserId,
				$this->autoModeratorUserName
			);

			$apiClient = Util::initializeApiClient();
			// Find if the User Talk page exists before we search for it
			$findApiResponse = [];
			if ( $userTalkPage->exists() ) {
				$findApiResponse = $apiClient->findComment( $this->talkPageMessageHeader, $userTalkPageTitle );
			}

			if ( array_key_exists( "couldredirect", $findApiResponse ) &&
				$findApiResponse[ "couldredirect" ] ) {
				// AutoModerator has already posted on this User Talk page this month
				// and the topic has not been deleted, adding a follow-up comment instead
				$pageInfoResponse = $apiClient->getUserTalkPageInfo( $userTalkPageTitle );
				// Getting the pageInformation to get the comment's id
				$headerId = $findApiResponse[ "id" ];
				$threadItems = $pageInfoResponse[ "discussiontoolspageinfo" ][ "threaditemshtml" ];
				foreach ( $threadItems as $threadItem ) {
					if ( $threadItem[ "id" ] === $headerId ) {
						// Getting the first reply id from this thread item
						$commentId = $threadItem[ "replies" ][ 0 ][ "id" ];
						$followUpComment = wfMessage( 'automoderator-wiki-revert-message-follow-up' )->params(
							$this->revId,
							$this->pageTitle
						)->plain();
						$apiClient->addFollowUpComment( $commentId, $userTalkPageTitle, $followUpComment,
							$autoModeratorUser );
						break;
					}
				}
			} else {
				// AutoModerator hasn't added a User Talk page message this month,
				// adding a new topic message
				$talkPageMessage = wfMessage( 'automoderator-wiki-revert-message' )->params(
					$this->autoModeratorUserName,
					$this->revId,
					$this->pageTitle,
					$this->falsePositiveReportPageTitle )->plain();
				$helpPageLink = $wikiConfig->get( 'AutoModeratorHelpPageLink' );
				if ( $helpPageLink ) {
					$helpPageBulletPoint = wfMessage( 'automoderator-wiki-revert-message-help-page' )->params(
						$helpPageLink
					)->plain();
					$apiClient->addTopic( $this->talkPageMessageHeader, $userTalkPageTitle,
						$talkPageMessage . "\n" . $helpPageBulletPoint, $this->talkPageMessageEditSummary,
						$autoModeratorUser );
				} else {
					$apiClient->addTopic( $this->talkPageMessageHeader, $userTalkPageTitle, $talkPageMessage,
						$this->talkPageMessageEditSummary, $autoModeratorUser );
				}
			}

		} catch ( RuntimeException $e ) {
			$this->setLastError( $e );
			$logger->error( $e->getMessage() );
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
}
