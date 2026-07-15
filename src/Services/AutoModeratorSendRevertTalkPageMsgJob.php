<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\AutoModerator\Services;

use MediaWiki\Extension\AutoModerator\AutoModeratorServices;
use MediaWiki\Extension\AutoModerator\Util;
use MediaWiki\JobQueue\Job;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use RuntimeException;

class AutoModeratorSendRevertTalkPageMsgJob extends Job {

	private bool $isRetryable = true;
	private int $revId;
	private ?string $pageTitle;
	private int $autoModeratorUserId;
	private string $autoModeratorUserName;
	private string $talkPageMessageHeader;
	private string $talkPageMessageEditSummary;
	private string $falsePositiveReportPageTitle;
	private int $rollbackRevId;

	private const string NO_USER_TALK_PAGE_ERROR_MESSAGE = 'Failed to retrieve user talk page title '
		. 'for sending AutoModerator revert talk page message.';

	private const string NO_PARENT_REVISION_FOUND = 'Failed to retrieve reverted revision from revision store.';

	/**
	 * @param Title $title
	 * @param array $params
	 *    - 'revId': (int)
	 *    - 'rollbackRevId': (int)
	 *    - 'autoModeratorUserId': (int)
	 *    - 'autoModeratorUserName': (string)
	 *    - 'talkPageMessageHeader': (string)
	 *    - 'talkPageMessageEditSummary': (string)
	 *    - 'falsePositiveReportPageTitle': (string)
	 */
	public function __construct( Title $title, array $params ) {
		parent::__construct( 'AutoModeratorSendRevertTalkPageMsgJob', $title, $params );
		$this->pageTitle = $title->getPrefixedText();
		$this->revId = $params['revId'];
		$this->rollbackRevId = $params['rollbackRevId'];
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
			$userFactory = $services->getUserFactory();
			$revisionStore = $services->getRevisionStore();
			$revision = $revisionStore->getRevisionById( $this->revId );
			if ( !$revision ) {
				$this->setLastError( self::NO_PARENT_REVISION_FOUND );
				$this->setAllowRetries( false );
				return false;
			}
			$userTalkPageTitle = $services->getTitleFactory()->makeTitleSafe(
				NS_USER_TALK,
				$revision->getUser()->getName()
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

			if ( array_key_exists( 'couldredirect', $findApiResponse ) &&
				$findApiResponse['couldredirect']
			) {
				// AutoModerator has already posted on this User Talk page this month
				// and the topic has not been deleted, adding a follow-up comment instead
				$pageInfoResponse = $apiClient->getUserTalkPageInfo( $userTalkPageTitle );
				// Getting the pageInformation to get the comment's id
				$headerId = $findApiResponse['id'];
				$threadItems = $pageInfoResponse['discussiontoolspageinfo']['threaditemshtml'];
				foreach ( $threadItems as $threadItem ) {
					if ( $threadItem['id'] === $headerId ) {
						// Getting the first reply id from this thread item
						$commentId = $threadItem['replies'][0]['id'];
						$followUpComment = wfMessage( 'automoderator-wiki-revert-message-follow-up' )->params(
							$this->rollbackRevId,
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
				$autoModeratorServices = AutoModeratorServices::wrap( $services );
				$config = $autoModeratorServices->getAutoModeratorConfig();
				$talkPageMessage = wfMessage( 'automoderator-wiki-revert-message' )->params(
					$this->autoModeratorUserName,
					$this->rollbackRevId,
					$this->pageTitle,
					$this->falsePositiveReportPageTitle )->plain();
				$helpPageLink = Util::getHelpPageLink( $config );
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
			$this->setLastError( $e->getMessage() );
			$logger->error( $e->getMessage() );
			return false;
		}
		return true;
	}

	private function setAllowRetries( bool $isRetryable ): void {
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
