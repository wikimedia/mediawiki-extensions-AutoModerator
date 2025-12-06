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

	public function __construct(
		private readonly Config $wikiConfig,
		private readonly UserGroupManager $userGroupManager,
		private readonly Config $config,
		private readonly WikiPageFactory $wikiPageFactory,
		private readonly RevisionStore $revisionStore,
		private readonly RestrictionStore $restrictionStore,
		private readonly JobQueueGroup $jobQueueGroup,
		private readonly PermissionManager $permissionManager,
	) {
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
		$enabledConfigKey = Util::isWikiMultilingual( $this->config )
			? "AutoModeratorMultilingualConfigEnableRevisionCheck"
			: "AutoModeratorEnableRevisionCheck";
		$revisionCheckEnabled = $this->wikiConfig->has( $enabledConfigKey )
			&& $this->wikiConfig->get( $enabledConfigKey );
		if ( !$revisionCheckEnabled ) {
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
