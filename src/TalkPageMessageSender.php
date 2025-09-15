<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 *
 * @file
 */

namespace AutoModerator;

use AutoModerator\Services\AutoModeratorSendRevertTalkPageMsgJob;
use Exception;
use MediaWiki\Config\Config;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\User;
use Psr\Log\LoggerInterface;
use Wikimedia\Timestamp\ConvertibleTimestamp;

class TalkPageMessageSender {

	private RevisionStore $revisionStore;

	private Config $config;

	private Config $wikiConfig;

	private JobQueueGroup $jobQueueGroup;

	private TitleFactory $titleFactory;

	public function __construct( RevisionStore $revisionStore, Config $config, Config $wikiConfig,
		JobQueueGroup $jobQueueGroup, TitleFactory $titleFactory ) {
		$this->revisionStore = $revisionStore;
		$this->config = $config;
		$this->wikiConfig = $wikiConfig;
		$this->jobQueueGroup = $jobQueueGroup;
		$this->titleFactory = $titleFactory;
	}

	/**
	 * @param Title $title
	 * @param int $revId
	 * @param int $rollbackRevId
	 * @param User $autoModeratorUser
	 * @param LoggerInterface $logger
	 * @return void
	 */
	public function insertAutoModeratorSendRevertTalkPageMsgJob(
		Title $title,
		int $revId,
		int $rollbackRevId,
		User $autoModeratorUser,
		LoggerInterface $logger
	): void {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'DiscussionTools' ) ) {
			// Discussion Tools is not loaded, we will not push a new job to the queue
			return;
		}
		try {
			$rev = $this->revisionStore->getRevisionById( $revId );
			if ( $rev === null ) {
				$logger->debug( __METHOD__ . ': AutoModerator skip rev - new page creation' );
				return;
			}
			$language = MediaWikiServices::getInstance()->getContentLanguage();
			$timestamp = new ConvertibleTimestamp();
			if ( $this->config->get( MainConfigNames::TranslateNumerals ) ) {
				$year = $language->formatNumNoSeparators( $timestamp->format( 'Y' ) );
			} else {
				$year = $timestamp->format( 'Y' );
			}
			$isMultiLingualRevertRiskEnabled = Util::isWikiMultilingual( $this->config );
			$falsePositivePageTitleText = $this->getFalsePositivePageTitleText( $isMultiLingualRevertRiskEnabled );
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
							$language->getMonthName( (int)$timestamp->format( 'n' ) ),
							$year,
							$autoModeratorUser->getName() )->plain(),
					'talkPageMessageEditSummary' => wfMessage( 'automoderator-wiki-revert-edit-summary' )
						->params( $title )->plain(),
					'falsePositiveReportPageTitle' => $falsePositivePageURL . $falsePositiveParams
				]
			);
			$this->jobQueueGroup->push( $userTalkPageJob );
			$logger->debug( 'AutoModeratorSendRevertTalkPageMsgJob pushed for {rev}', [
				'rev' => $revId,
			] );
		} catch ( Exception $e ) {
			$msg = $e->getMessage();
			$logger->error( 'AutoModeratorSendRevertTalkPageMsgJob push failed for {rev}: {msg}', [
				'rev' => $revId,
				'msg' => $msg
			] );
		}
	}

	/**
	 * @param bool $isMultiLingualRevertRiskEnabled
	 * @return mixed
	 */
	private function getFalsePositivePageTitleText( bool $isMultiLingualRevertRiskEnabled ): mixed {
		return $isMultiLingualRevertRiskEnabled ?
			$this->wikiConfig->get( "AutoModeratorMultilingualConfigFalsePositivePageTitle" ) :
			$this->wikiConfig->get( "AutoModeratorFalsePositivePageTitle" );
	}

}
