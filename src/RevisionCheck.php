<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\AutoModerator;

use MediaWiki\ChangeTags\ChangeTags;
use MediaWiki\Config\Config;
use MediaWiki\Extension\AutoModerator\Services\AutoModeratorRollback;
use MediaWiki\Page\WikiPage;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Permissions\RestrictionStore;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\User\ExternalUserNames;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;
use Psr\Log\LoggerInterface;
use StatusValue;

readonly class RevisionCheck {

	/**
	 * @param Config $config
	 * @param AutoModeratorRollback $rollbackPage
	 * @param bool $enforce Perform reverts if true, take no action if false
	 */
	public function __construct(
		private Config $config,
		private AutoModeratorRollback $rollbackPage,
		private bool $enforce = false,
	) {
	}

	/**
	 * Perform rollback.
	 */
	private function doRollback(): StatusValue {
		return $this->rollbackPage
			->rollback();
	}

	/**
	 * Precheck a revision; if any of the checks don't pass, a revision won't be scored.
	 */
	public static function revertPreCheck(
		UserIdentity $user,
		User $autoModeratorUser,
		LoggerInterface $logger,
		RevisionStore $revisionStore,
		array $tags,
		RestrictionStore $restrictionStore,
		WikiPageFactory $wikiPageFactory,
		Config $config,
		RevisionRecord $rev,
		PermissionManager $permissionManager
	): bool {
		$wikiPageId = $rev->getPageId();
		// Skips reverts if AutoModerator is blocked
		$autoModeratorBlock = $autoModeratorUser->getBlock();
		if ( $autoModeratorBlock && $autoModeratorBlock->appliesToPage( $wikiPageId ) ) {
			$logger->debug( __METHOD__ . ': AutoModerator skip rev - AutoModerator is blocked' );
			return false;
		}
		// Skip AutoModerator edits
		if ( self::areUsersEqual( $user, $autoModeratorUser ) ) {
			$logger->debug( __METHOD__ . ': AutoModerator skip rev - AutoMod edits' );
			return false;
		}
		$parentId = $rev->getParentId();
		// Skip new page creations
		if ( self::isNewPageCreation( $parentId ) ) {
			$logger->debug( __METHOD__ . ': AutoModerator skip rev - new page creation' );
			return false;
		}
		// Skip already reverted edits
		if ( in_array( ChangeTags::TAG_REVERTED, $tags ) ) {
			$logger->debug( __METHOD__ . ': AutoModerator skip rev - already reverted' );
			return false;
		}
		// Skip reverts made to an AutoModerator bot revert or if
		// the user reverts their own edit
		if ( array_intersect( $tags, ChangeTags::REVERT_TAGS ) ) {
			$parentRev = $revisionStore->getRevisionById( $parentId );
			if ( !$parentRev ) {
				$logger->debug( __METHOD__ . ': AutoModerator skip rev - parent revision not found' );
				return false;
			}
			$parentRevUser = $parentRev->getUser();
			if ( $parentRevUser === null ) {
				$logger->debug( __METHOD__ . ': AutoModerator skip rev - parent revision user is null' );
				return false;
			}
			if ( self::areUsersEqual( $parentRevUser, $autoModeratorUser ) ) {
				$logger->debug( __METHOD__ . ': AutoModerator skip rev - AutoModerator reverts' );
				return false;
			}
			if ( self::areUsersEqual( $parentRevUser, $user ) ) {
				$logger->debug( __METHOD__ . ': AutoModerator skip rev - own reverts' );
				return false;
			}
		}
		// Skip page moves
		$moveTags = [
			ChangeTags::TAG_NEW_REDIRECT, ChangeTags::TAG_REMOVED_REDIRECT, ChangeTags::TAG_CHANGED_REDIRECT_TARGET
		];
		foreach ( $moveTags as $moveTag ) {
			if ( in_array( $moveTag, $tags ) ) {
				$logger->debug( __METHOD__ . ': AutoModerator skip rev - page move' );
				return false;
			}
		}
		// Skip edits from editors that have certain user rights
		if ( self::shouldSkipUser( $permissionManager, $user, $config ) ) {
			$logger->debug( __METHOD__ . ': AutoModerator skip rev - trusted user rights edits' );
			return false;
		}
		// Skip external users
		if ( ExternalUserNames::isExternal( $user->getName() ) ) {
			$logger->debug( __METHOD__ . ': AutoModerator skip rev - external user' );
			return false;
		}
		$wikiPage = $wikiPageFactory->newFromID( $wikiPageId );
		// Skip null pages
		if ( $wikiPage === null ) {
			$logger->debug( __METHOD__ . ': AutoModerator skip rev - wikiPage is null' );
			return false;
		}
		// Skip non-mainspace edit
		if ( $wikiPage->getNamespace() !== NS_MAIN ) {
			$logger->debug( __METHOD__ . ': AutoModerator skip rev - non-mainspace edits' );
			return false;
		}
		// Skip protected pages that only admins can edit.
		// Automoderator should be able to revert semi-protected pages,
		// so we won't be skipping those on pre-check.
		if ( self::isProtectedPage( $restrictionStore, $wikiPage ) ) {
			$logger->debug( __METHOD__ . ': AutoModerator skip rev - protected page' );
			return false;
		}
		return true;
	}

	/**
	 * Check revision; revert if it meets configured critera
	 */
	public function maybeRollback( array $score ): RollbackStatus {
		$reverted = 0;
		$status = 'Not reverted';
		$probability = $score[ 'output' ][ 'probabilities' ][ 'true' ];
		// Check if the threshold should be taken from the language-agnostic
		// or the multilingual model based on what model was chosen in the job
		if ( $probability > Util::getRevertThreshold( $this->config ) ) {
			$shouldRevert = true;
			if ( $this->enforce ) {
				// early return if we are in log only mode
				$logModeEnabled = Util::getEnableLogOnlyMode( $this->config );
				if ( $logModeEnabled ) {
					return new RollbackStatus( $reverted, $status, $shouldRevert );
				}
				$pageRollbackStatus = $this->doRollback();
				if ( !$pageRollbackStatus->isOK() ) {
					$errorMessages = $pageRollbackStatus->getMessages( 'error' );
					// checks to see if there was an edit conflict or already rolled error message
					// which would indicate that someone else
					// has edited or rolled the page since the job began
					if ( $errorMessages &&
						( $errorMessages[0]->getKey() === "alreadyrolled"
							|| $errorMessages[0]->getKey() === "edit-conflict" ) ) {
						$status = 'success';
						return new RollbackStatus( $reverted, $status, $shouldRevert );
					}
					return new RollbackStatus( $reverted, $errorMessages ?
							wfMessage( $errorMessages[0] )->inLanguage( "en" )->plain()
							: "Failed to save revision", $shouldRevert );
				}
			}
			$reverted = 1;
			$status = 'success';
		}
		return new RollbackStatus( $reverted, $status );
	}

	public static function shouldSkipUser(
		PermissionManager $permissionManager,
		UserIdentity $user,
		Config $config
	): bool {
		$userRightsToSkip = Util::getSkipUserRights( $config );
		return $permissionManager->userHasAnyRight( $user, ...(array)$userRightsToSkip );
	}

	public static function areUsersEqual( UserIdentity $user, UserIdentity $autoModeratorUser ): bool {
		return $user->equals( $autoModeratorUser );
	}

	public static function isProtectedPage( RestrictionStore $restrictionStore, WikiPage $wikiPage ): bool {
		return $restrictionStore->isProtected( $wikiPage )
			&& !$restrictionStore->isSemiProtected( $wikiPage );
	}

	public static function isNewPageCreation( ?int $parentId ): bool {
		return $parentId === null || $parentId === 0;
	}
}
