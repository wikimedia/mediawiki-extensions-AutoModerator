<?php

namespace AutoModerator\Hooks;

use AutoModerator\RevisionCheck;
use AutoModerator\Services\AutoModeratorFetchRevScoreJob;
use AutoModerator\Util;
use Exception;
use MediaWiki\Config\Config;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Page\Hook\RevisionFromEditCompleteHook;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Permissions\RestrictionStore;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\User\UserGroupManager;

class RevisionFromEditCompleteHookHandler implements RevisionFromEditCompleteHook {

	private Config $wikiConfig;

	private UserGroupManager $userGroupManager;

	private Config $config;

	private WikiPageFactory $wikiPageFactory;

	private RevisionStore $revisionStore;

	private RestrictionStore $restrictionStore;

	private JobQueueGroup $jobQueueGroup;

	private PermissionManager $permissionManager;

	/**
	 * @param Config $wikiConfig
	 * @param UserGroupManager $userGroupManager
	 * @param Config $config
	 * @param WikiPageFactory $wikiPageFactory
	 * @param RevisionStore $revisionStore
	 * @param RestrictionStore $restrictionStore
	 * @param JobQueueGroup $jobQueueGroup
	 * @param PermissionManager $permissionManager
	 */
	public function __construct(
		Config $wikiConfig,
		UserGroupManager $userGroupManager,
		Config $config,
		WikiPageFactory $wikiPageFactory,
		RevisionStore $revisionStore,
		RestrictionStore $restrictionStore,
		JobQueueGroup $jobQueueGroup,
		PermissionManager $permissionManager
	) {
		$this->wikiConfig = $wikiConfig;
		$this->userGroupManager = $userGroupManager;
		$this->config = $config;
		$this->wikiPageFactory = $wikiPageFactory;
		$this->revisionStore = $revisionStore;
		$this->restrictionStore = $restrictionStore;
		$this->jobQueueGroup = $jobQueueGroup;
		$this->permissionManager = $permissionManager;
	}

	/**
	 * @inheritDoc
	 */
	public function onRevisionFromEditComplete( $wikiPage, $rev, $originalRevId, $user, &$tags ) {
		if ( ExtensionRegistry::getInstance()->isLoaded( 'ORES' ) ) {
			$oresModels = $this->config->get( 'OresModels' );

			if ( array_key_exists( 'revertrisklanguageagnostic', $oresModels ) &&
				$oresModels[ 'revertrisklanguageagnostic' ][ 'enabled' ]
			) {
				// ORES is loaded and model is enabled; not calling the job from this hook handler
				return;
			}
		}

		if ( !$wikiPage || !$rev || !$user ) {
			return;
		}
		$revisionCheckNotEnabled = $this->wikiConfig->has( 'AutoModeratorEnableRevisionCheck' ) &&
		!$this->wikiConfig->get( 'AutoModeratorEnableRevisionCheck' );
		$multilingualRevisionCheckNotEnabled = $this->wikiConfig
			->has( 'AutoModeratorMultilingualConfigEnableRevisionCheck' )
			&& !$this->wikiConfig->get( 'AutoModeratorMultilingualConfigEnableRevisionCheck' );
		if ( $revisionCheckNotEnabled && $multilingualRevisionCheckNotEnabled ) {
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
			$this->wikiConfig,
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
