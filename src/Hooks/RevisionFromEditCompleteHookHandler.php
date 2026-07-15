<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\AutoModerator\Hooks;

use Exception;
use MediaWiki\Config\Config;
use MediaWiki\Extension\AutoModerator\RevisionCheck;
use MediaWiki\Extension\AutoModerator\Services\AutoModeratorFetchRevScoreJob;
use MediaWiki\Extension\AutoModerator\Util;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Page\Hook\RevisionFromEditCompleteHook;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Permissions\RestrictionStore;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\User\UserGroupManager;

readonly class RevisionFromEditCompleteHookHandler implements RevisionFromEditCompleteHook {

	public function __construct(
		private UserGroupManager $userGroupManager,
		private Config $config,
		private WikiPageFactory $wikiPageFactory,
		private RevisionStore $revisionStore,
		private RestrictionStore $restrictionStore,
		private JobQueueGroup $jobQueueGroup,
		private PermissionManager $permissionManager,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function onRevisionFromEditComplete( $wikiPage, $rev, $originalRevId, $user, &$tags ): void {
		if ( Util::doesORESSupportRevertRiskModel( $this->config ) ) {
			// ORES is loaded and model is enabled; not calling the job from this hook handler.
			return;
		}

		if ( !$wikiPage || !$rev || !$user ) {
			return;
		}
		if ( !Util::getEnableRevisionCheck( $this->config ) ) {
			return;
		}
		$autoModeratorUser = Util::getAutoModeratorUser( $this->config, $this->userGroupManager );
		$userId = $user->getId();
		$logger = LoggerFactory::getInstance( 'AutoModerator' );
		$title = $wikiPage->getTitle();
		$wikiPageId = $wikiPage->getId();
		$revId = $rev->getId();
		if ( !RevisionCheck::revertPreCheck(
			$user,
			$autoModeratorUser,
			$logger,
			$this->revisionStore,
			$tags,
			$this->restrictionStore,
			$this->wikiPageFactory,
			$this->config,
			$rev,
			$this->permissionManager ) ) {
			return;
		}

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
				'scores' => null
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
