<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\AutoModerator\Hooks;

use Exception;
use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\Config\Config;
use MediaWiki\Extension\AutoModerator\RevisionCheck;
use MediaWiki\Extension\AutoModerator\Services\AutoModeratorFetchRevScoreJob;
use MediaWiki\Extension\AutoModerator\Util;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Permissions\RestrictionStore;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\User\UserGroupManager;
use ORES\Hooks\ORESRecentChangeScoreSavedHook;
use Wikimedia\Rdbms\IConnectionProvider;

readonly class ORESRecentChangeScoreSavedHookHandler implements ORESRecentChangeScoreSavedHook {

	public function __construct(
		private UserGroupManager $userGroupManager,
		private Config $config,
		private WikiPageFactory $wikiPageFactory,
		private RevisionStore $revisionStore,
		private RestrictionStore $restrictionStore,
		private JobQueueGroup $jobQueueGroup,
		private ChangeTagsStore $changeTagsStore,
		private PermissionManager $permissionManager,
		private IConnectionProvider $connectionProvider,
	) {
	}

	public function onORESRecentChangeScoreSavedHook( ?RevisionRecord $revision, ?array $scores ): void {
		$logger = LoggerFactory::getInstance( 'AutoModerator' );
		$logger->debug( 'onORESRecentChangeScoreSavedHook called for {rev}', [
			'rev' => $revision?->getId(),
		] );

		if ( !Util::doesORESSupportRevertRiskModel( $this->config ) ) {
			// ORES does not support the revert risk model; not calling the job from this hook handler.
			return;
		}

		if ( !$revision || !$scores ) {
			return;
		}
		if ( !Util::getEnableRevisionCheck( $this->config ) ) {
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
		$autoModeratorUser = Util::getAutoModeratorUser( $this->config, $this->userGroupManager );
		$userId = $user->getId();
		if ( !RevisionCheck::revertPreCheck(
			$user,
			$autoModeratorUser,
			$logger,
			$this->revisionStore,
			$tags,
			$this->restrictionStore,
			$this->wikiPageFactory,
			$this->config,
			$revision,
			$this->permissionManager ) ) {
			return;
		}

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
