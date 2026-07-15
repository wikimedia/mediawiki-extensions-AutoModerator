<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\AutoModerator;

use Exception;
use MediaWiki\Config\Config;
use MediaWiki\Extension\AutoModerator\Services\AutoModeratorSendRevertTalkPageMsgJob;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\Language\Language;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\User;
use Psr\Log\LoggerInterface;
use Wikimedia\Timestamp\ConvertibleTimestamp;

readonly class TalkPageMessageSender {

	private LoggerInterface $logger;

	public function __construct(
		private RevisionStore $revisionStore,
		private Config $config,
		private JobQueueGroup $jobQueueGroup,
		private TitleFactory $titleFactory,
		private Language $contentLanguage,
		?LoggerInterface $logger = null,
	) {
		$this->logger = $logger ?? LoggerFactory::getInstance( 'AutoModerator' );
	}

	public function insertAutoModeratorSendRevertTalkPageMsgJob(
		Title $title,
		int $revId,
		int $rollbackRevId,
		User $autoModeratorUser
	): void {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'DiscussionTools' ) ) {
			// Discussion Tools is not loaded, we will not push a new job to the queue
			return;
		}
		try {
			$rev = $this->revisionStore->getRevisionById( $revId );
			if ( $rev === null ) {
				$this->logger->debug( __METHOD__ . ': AutoModerator skip rev - new page creation' );
				return;
			}
			$timestamp = new ConvertibleTimestamp();
			if ( $this->config->get( MainConfigNames::TranslateNumerals ) ) {
				$year = $this->contentLanguage->formatNumNoSeparators( $timestamp->format( 'Y' ) );
			} else {
				$year = $timestamp->format( 'Y' );
			}
			$falsePositivePageTitleText = Util::getFalsePositivePageTitleText( $this->config );
			$falsePositivePageTitle = $this->titleFactory->newFromText( $falsePositivePageTitleText );
			if ( !$falsePositivePageTitle ) {
				$falsePositivePageURL = "";
				$falsePositiveParams = "";
			} else {
				$falsePositivePageURL = $falsePositivePageTitle->getFullURL();
				$falsePositivePreloadTemplate = $falsePositivePageTitle->getNsText() . ":" .
					$falsePositivePageTitle->getDBkey() . '/Preload';
				$pageTitle = $this->titleFactory->newFromPageIdentity( $rev->getPage() )->getDBkey();
				$falsePositiveParams = '?action=edit&section=new&nosummary=true&preload=' .
					$falsePositivePreloadTemplate . '&preloadparams%5B%5D=' . $revId .
					'&preloadparams%5B%5D=' . $pageTitle;
			}

			$userTalkPageJob = new AutoModeratorSendRevertTalkPageMsgJob(
				$title,
				[
					'revId' => $revId,
					'rollbackRevId' => $rollbackRevId,
					// The test/production environments do not work when you pass the entire User object.
					// To get around this, we have split the required parameters from the User object
					// into individual parameters so that the test/production Job constructor will accept them.
					'autoModeratorUserId' => $autoModeratorUser->getId(),
					'autoModeratorUserName' => $autoModeratorUser->getName(),
					'talkPageMessageHeader' => wfMessage( 'automoderator-wiki-revert-message-header' )
						->params(
							$this->contentLanguage->getMonthName( (int)$timestamp->format( 'n' ) ),
							$year,
							$autoModeratorUser->getName()
						)->plain(),
					'talkPageMessageEditSummary' => wfMessage( 'automoderator-wiki-revert-edit-summary' )
						->params( $title->getPrefixedText() )->plain(),
					'falsePositiveReportPageTitle' => $falsePositivePageURL . $falsePositiveParams
				]
			);
			$this->jobQueueGroup->push( $userTalkPageJob );
			$this->logger->debug( 'AutoModeratorSendRevertTalkPageMsgJob pushed for {rev}', [
				'rev' => $revId,
			] );
		} catch ( Exception $e ) {
			$msg = $e->getMessage();
			$this->logger->error( 'AutoModeratorSendRevertTalkPageMsgJob push failed for {rev}: {msg}', [
				'rev' => $revId,
				'msg' => $msg
			] );
		}
	}

}
