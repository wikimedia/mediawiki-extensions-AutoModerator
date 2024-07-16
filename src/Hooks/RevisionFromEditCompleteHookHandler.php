<?php
/**
 * Copyright (C) 2016 Brad Jorsch <bjorsch@wikimedia.org>
 *
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
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace AutoModerator\Hooks;

use AutoModerator\RevisionCheck;
use AutoModerator\Services\AutoModeratorFetchRevScoreJob;
use AutoModerator\Services\AutoModeratorSendRevertTalkPageMsgJob;
use AutoModerator\Util;
use Exception;
use JobQueueGroup;
use MediaWiki\Config\Config;
use MediaWiki\Content\ContentHandlerFactory;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Permissions\RestrictionStore;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\User;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentity;
use Psr\Log\LoggerInterface;
use WikiPage;

class RevisionFromEditCompleteHookHandler {

	private Config $wikiConfig;

	private UserGroupManager $userGroupManager;

	private Config $config;

	private WikiPageFactory $wikiPageFactory;

	private RevisionStore $revisionStore;

	private ContentHandlerFactory $contentHandlerFactory;

	private RestrictionStore $restrictionStore;

	private JobQueueGroup $jobQueueGroup;

	private TitleFactory $titleFactory;

	/**
	 * @param Config $wikiConfig
	 * @param UserGroupManager $userGroupManager
	 * @param Config $config
	 * @param WikiPageFactory $wikiPageFactory
	 * @param RevisionStore $revisionStore
	 * @param ContentHandlerFactory $contentHandlerFactory
	 * @param RestrictionStore $restrictionStore
	 * @param JobQueueGroup $jobQueueGroup
	 * @param TitleFactory $titleFactory
	 */
	public function __construct( Config $wikiConfig, UserGroupManager $userGroupManager, Config $config,
		WikiPageFactory $wikiPageFactory, RevisionStore $revisionStore, ContentHandlerFactory $contentHandlerFactory,
		RestrictionStore $restrictionStore, JobQueueGroup $jobQueueGroup, TitleFactory $titleFactory ) {
			$this->wikiConfig = $wikiConfig;
			$this->userGroupManager = $userGroupManager;
			$this->config = $config;
			$this->wikiPageFactory = $wikiPageFactory;
			$this->revisionStore = $revisionStore;
			$this->contentHandlerFactory = $contentHandlerFactory;
			$this->restrictionStore = $restrictionStore;
			$this->titleFactory = $titleFactory;
			$this->jobQueueGroup = $jobQueueGroup;
	}

	/**
	 * @param WikiPage $wikiPage
	 * @param RevisionRecord $rev
	 * @param int|false $originalRevId
	 * @param UserIdentity $user
	 * @param string[] &$tags
	 */
	public function handle(
		$wikiPage, $rev, $originalRevId, $user, &$tags
	) {
		if ( !$wikiPage || !$rev || !$user ) {
			return;
		}
		if ( !$this->wikiConfig->get( 'AutoModeratorEnableRevisionCheck' ) ) {
			return;
		}
		$autoModeratorUser = Util::getAutoModeratorUser( $this->config, $this->userGroupManager );
		$userId = $user->getId();
		$logger = LoggerFactory::getInstance( 'AutoModerator' );
		$title = $wikiPage->getTitle();
		$wikiPageId = $wikiPage->getId();
		$revId = $rev->getId();
		if ( $autoModeratorUser->getId() === $userId && in_array( 'mw-undo', $tags ) ) {
			if ( $this->wikiConfig->get( 'AutoModeratorRevertTalkPageMessageEnabled' ) ) {
				$this->insertAutoModeratorSendRevertTalkPageMsgJob(
					$title,
					$wikiPageId,
					$revId,
					$autoModeratorUser,
					$logger );
			}
			return;
		}
		if ( !RevisionCheck::revertPreCheck(
			$user,
			$autoModeratorUser,
			$logger,
			$this->revisionStore,
			$tags,
			$this->restrictionStore,
			$this->wikiPageFactory,
			$this->userGroupManager,
			$this->wikiConfig,
			$revId,
			$wikiPageId ) ) {
			return;
		}
		$undoSummaryMessageKey = ( !$user->isRegistered() && $this->config->get( MainConfigNames::DisableAnonTalk ) )
			? 'automoderator-wiki-undo-summary-anon' : 'automoderator-wiki-undo-summary';
		$job = new AutoModeratorFetchRevScoreJob( $title,
			[
				'wikiPageId' => $wikiPageId,
				'revId' => $revId,
				'originalRevId' => $originalRevId,
				// The test/production environments do not work when you pass the entire User object.
				// To get around this, we have split the required parameters from the User object
				// into individual parameters so that the test/production Job constructor will accept them.
				'userId' => $userId,
				'userName' => $user->getName(),
				'tags' => $tags,
				'undoSummary' => wfMessage( $undoSummaryMessageKey )->rawParams( $revId, $user->getName() )->plain()
			]
		);
		try {
			$this->jobQueueGroup->lazyPush( $job );
			$logger->debug( 'Job pushed for {rev}', [
				'rev' => $revId,
			] );
		} catch ( Exception $e ) {
			$msg = $e->getMessage();
			$logger->error( 'Job push failed for {rev}: {msg}', [
				'rev' => $revId,
				'msg' => $msg
			] );
		}
	}

	/**
	 * @param Title $title
	 * @param int $wikiPageId
	 * @param int|null $revId
	 * @param User $autoModeratorUser
	 * @param LoggerInterface $logger
	 * @return void
	 */
	public function insertAutoModeratorSendRevertTalkPageMsgJob(
		Title $title,
		int $wikiPageId,
		?int $revId,
		User $autoModeratorUser,
		LoggerInterface $logger ): void {
		try {
			$rev = $this->revisionStore->getRevisionById( $revId );
			if ( $rev === null ) {
				$logger->debug( "AutoModerator skip rev" . __METHOD__ . " - new page creation" );
				return;
			}
			$parentRevId = $rev->getParentId();
			if ( $parentRevId === null ) {
				$logger->debug( "AutoModerator skip rev" . __METHOD__ . " - new page creation" );
				return;
			}
			$userTalkPageJob = new AutoModeratorSendRevertTalkPageMsgJob(
				$title,
				[
					'wikiPageId' => $wikiPageId,
					'revId' => $revId,
					'parentRevId' => $parentRevId,
					// The test/production environments do not work when you pass the entire User object.
					// To get around this, we have split the required parameters from the User object
					// into individual parameters so that the test/production Job constructor will accept them.
					'autoModeratorUserId' => $autoModeratorUser->getId(),
					'autoModeratorUserName' => $autoModeratorUser->getName(),
					'talkPageMessageHeader' => wfMessage( 'automoderator-wiki-revert-message-header' )
						->params( $autoModeratorUser->getName() ),
					'talkPageMessageEditSummary' => wfMessage( 'automoderator-wiki-revert-edit-summary' )
						->params( $title )->plain(),
					'falsePositiveReportPageId' => $this->wikiConfig->get( "AutoModeratorFalsePositivePageTitle" ),
					'wikiId' => Util::getWikiID( $this->config ),
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
}
