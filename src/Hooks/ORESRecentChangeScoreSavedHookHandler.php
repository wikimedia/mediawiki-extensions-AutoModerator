<?php

namespace AutoModerator\Hooks;

use AutoModerator\RevisionCheck;
use AutoModerator\Services\AutoModeratorFetchRevScoreJob;
use AutoModerator\TalkPageMessageSender;
use AutoModerator\Util;
use Exception;
use JobQueueGroup;
use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\Config\Config;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Permissions\RestrictionStore;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\User\UserGroupManager;
use ORES\Hooks\ORESRecentChangeScoreSavedHook;
use Wikimedia\Rdbms\IConnectionProvider;

class ORESRecentChangeScoreSavedHookHandler implements ORESRecentChangeScoreSavedHook {

	private Config $wikiConfig;

	private UserGroupManager $userGroupManager;

	private Config $config;

	private WikiPageFactory $wikiPageFactory;

	private RevisionStore $revisionStore;

	private RestrictionStore $restrictionStore;

	private JobQueueGroup $jobQueueGroup;

	private ChangeTagsStore $changeTagsStore;

	private PermissionManager $permissionManager;

	private IConnectionProvider $connectionProvider;

	/**
	 * @param Config $wikiConfig
	 * @param UserGroupManager $userGroupManager
	 * @param Config $config
	 * @param WikiPageFactory $wikiPageFactory
	 * @param RevisionStore $revisionStore
	 * @param RestrictionStore $restrictionStore
	 * @param JobQueueGroup $jobQueueGroup
	 * @param ChangeTagsStore $changeTagsStore
	 * @param PermissionManager $permissionManager
	 * @param IConnectionProvider $connectionProvider
	 */
	public function __construct(
		Config $wikiConfig,
		UserGroupManager $userGroupManager,
		Config $config,
		WikiPageFactory $wikiPageFactory,
		RevisionStore $revisionStore,
		RestrictionStore $restrictionStore,
		JobQueueGroup $jobQueueGroup,
		ChangeTagsStore $changeTagsStore,
		PermissionManager $permissionManager,
		IConnectionProvider $connectionProvider
	) {
			$this->wikiConfig = $wikiConfig;
			$this->userGroupManager = $userGroupManager;
			$this->config = $config;
			$this->wikiPageFactory = $wikiPageFactory;
			$this->revisionStore = $revisionStore;
			$this->restrictionStore = $restrictionStore;
			$this->jobQueueGroup = $jobQueueGroup;
			$this->changeTagsStore = $changeTagsStore;
			$this->permissionManager = $permissionManager;
			$this->connectionProvider = $connectionProvider;
	}

	/**
	 * @param RevisionRecord|null $revision
	 * @param array $scores
	 */
	public function onORESRecentChangeScoreSavedHook( $revision, $scores ) {
		if ( !$revision || !$scores ) {
			return;
		}
		if ( !$this->wikiConfig->get( 'AutoModeratorEnableRevisionCheck' ) ) {
			return;
		}
		$user = $revision->getUser();
		if ( !$user ) {
			return;
		}
		$wikiPageId = $revision->getPageId();
		if ( !$wikiPageId ) {
			return;
		}
		$wikiPage = $this->wikiPageFactory->newFromID( $wikiPageId );
		if ( !$wikiPage ) {
			return;
		}

		$title = $wikiPage->getTitle();
		$revId = $revision->getId();
		$dbr = $this->connectionProvider->getReplicaDatabase();
		$tags = $this->changeTagsStore->getTags( $dbr, null, $revId, null );
		$logger = LoggerFactory::getInstance( 'AutoModerator' );
		$autoModeratorUser = Util::getAutoModeratorUser( $this->config, $this->userGroupManager );
		$userId = $user->getId();
		if ( $autoModeratorUser->getId() === $userId && in_array( 'mw-undo', $tags ) ) {
			if ( $this->wikiConfig->get( 'AutoModeratorRevertTalkPageMessageEnabled' ) ) {
				$talkPageMessageSender = new TalkPageMessageSender( $this->revisionStore, $this->config,
					$this->wikiConfig, $this->jobQueueGroup );
				$talkPageMessageSender->insertAutoModeratorSendRevertTalkPageMsgJob( $title, $wikiPageId, $revId,
					$autoModeratorUser, $logger );
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
			$this->wikiConfig,
			$revId,
			$wikiPageId,
			$this->permissionManager ) ) {
			return;
		}
		$undoSummaryMessageKey = ( !$user->isRegistered() && $this->config->get( MainConfigNames::DisableAnonTalk ) )
			? 'automoderator-wiki-undo-summary-anon' : 'automoderator-wiki-undo-summary';

		$job = new AutoModeratorFetchRevScoreJob( $title,
			[
				'wikiPageId' => $wikiPageId,
				'revId' => $revId,
				'originalRevId' => false,
				// The test/production environments do not work when you pass the entire User object.
				// To get around this, we have split the required parameters from the User object
				// into individual parameters so that the test/production Job constructor will accept them.
				'userId' => $userId,
				'userName' => $user->getName(),
				'tags' => $tags,
				'undoSummary' => wfMessage( $undoSummaryMessageKey )->rawParams( $revId, $user->getName() )->plain(),
				// The score will be evaluated in the job to see whether the revision should be reverted or not
				'scores' => $scores
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
}