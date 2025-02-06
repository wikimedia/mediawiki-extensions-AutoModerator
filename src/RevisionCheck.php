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

use AutoModerator\Services\AutoModeratorRollback;
use ChangeTags;
use MediaWiki\Config\Config;
use MediaWiki\Content\Content;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Permissions\RestrictionStore;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\User\ExternalUserNames;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;
use Psr\Log\LoggerInterface;
use StatusValue;
use WikiPage;

class RevisionCheck {

	/** @var int */
	private int $wikiPageId;

	/** @var WikiPageFactory */
	private WikiPageFactory $wikiPageFactory;

	/** @var int */
	private int $revId;

	/** @var User */
	private User $autoModeratorUser;

	/** @var RevisionStore */
	private RevisionStore $revisionStore;

	/** @var Config */
	private Config $wikiConfig;

	/** @var Config */
	private Config $config;

	/** @var string */
	public string $undoSummary;

	/** @var ContentHandler */
	private ContentHandler $contentHandler;

	/** @var bool */
	private bool $enforce;

	/** @var AutoModeratorRollback */
	private AutoModeratorRollback $rollbackPage;

	/**
	 * @param int $wikiPageId WikiPage ID of
	 * @param WikiPageFactory $wikiPageFactory
	 * @param int $revId New revision ID
	 * @param User $autoModeratorUser reverting user
	 * @param RevisionStore $revisionStore
	 * @param Config $wikiConfig
	 * @param Config $config
	 * @param ContentHandler $contentHandler
	 * @param string $undoSummary
	 * @param AutoModeratorRollback $rollbackPage
	 * @param bool $enforce Perform reverts if true, take no action if false
	 */
	public function __construct(
		int $wikiPageId,
		WikiPageFactory $wikiPageFactory,
		int $revId,
		User $autoModeratorUser,
		RevisionStore $revisionStore,
		Config $wikiConfig,
		Config $config,
		ContentHandler $contentHandler,
		string $undoSummary,
		AutoModeratorRollback $rollbackPage,
		bool $enforce = false
	) {
		$this->wikiPageId = $wikiPageId;
		$this->wikiPageFactory = $wikiPageFactory;
		$this->revId = $revId;
		$this->autoModeratorUser = $autoModeratorUser;
		$this->revisionStore = $revisionStore;
		$this->wikiConfig = $wikiConfig;
		$this->config = $config;
		$this->contentHandler = $contentHandler;
		$this->enforce = $enforce;
		$this->undoSummary = $undoSummary;
		$this->rollbackPage = $rollbackPage;
	}

	/**
	 * Cribbed from EditPage.php
	 * Returns the result of a three-way merge when undoing changes.
	 *
	 * @param RevisionRecord $oldRev Revision that is being restored. Corresponds to
	 *        `undoafter` URL parameter.
	 * @param ?string &$error If false is returned, this will be set to "norev"
	 *   if the revision failed to load, or "failure" if the content handler
	 *   failed to merge the required changes.
	 * @param WikiPage $wikiPage
	 * @param RevisionRecord $rev
	 *
	 * @return false|Content
	 */
	private function getUndoContent(
		RevisionRecord $oldRev,
		?string &$error,
		WikiPage $wikiPage,
		RevisionRecord $rev
	) {
		$currentContent = $wikiPage->getRevisionRecord()
			->getContent( SlotRecord::MAIN );
		$undoContent = $rev->getContent( SlotRecord::MAIN );
		$undoAfterContent = $oldRev->getContent( SlotRecord::MAIN );
		$undoIsLatest = $wikiPage->getRevisionRecord()->getId() === $this->revId;
		if ( $currentContent === null
			|| $undoContent === null
			|| $undoAfterContent === null
		) {
			$error = 'norev';
			return false;
		}

		$content = $this->contentHandler->getUndoContent(
			$currentContent,
			$undoContent,
			$undoAfterContent,
			$undoIsLatest,
		);
		if ( $content === false ) {
			$error = 'failure';
		}
		return $content;
	}

	/**
	 * Perform rollback
	 */
	private function doRollback(): StatusValue {
		return $this->rollbackPage
			->setSummary( $this->undoSummary )
			->rollback();
	}

	/**
	 * Precheck a revision; if any of the checks don't pass,
	 * a revision won't be scored
	 * @param UserIdentity $user
	 * @param User $autoModeratorUser
	 * @param LoggerInterface $logger
	 * @param RevisionStore $revisionStore
	 * @param string[] $tags
	 * @param RestrictionStore $restrictionStore
	 * @param WikiPageFactory $wikiPageFactory
	 * @param Config $wikiConfig
	 * @param int $revId
	 * @param int $wikiPageId
	 * @return bool
	 */
	public static function revertPreCheck( UserIdentity $user, User $autoModeratorUser, LoggerInterface $logger,
		RevisionStore $revisionStore, array $tags, RestrictionStore $restrictionStore, WikiPageFactory $wikiPageFactory,
		Config $wikiConfig, int $revId, int $wikiPageId, PermissionManager $permissionManager ): bool {
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
		$parentId = $revisionStore->getRevisionById( $revId )->getParentId();
		// Skip new page creations
		if ( self::isNewPageCreation( $parentId ) ) {
			$logger->debug( __METHOD__ . ': AutoModerator skip rev - new page creation' );
			return false;
		}
		// Skip reverts made to an AutoModerator bot revert or if
		// the user reverts their own edit
		$revertTags = [
			ChangeTags::TAG_MANUAL_REVERT, ChangeTags::TAG_ROLLBACK, ChangeTags::TAG_UNDO, ChangeTags::TAG_REVERTED
		];
		$parentRev = $revisionStore->getRevisionById( $parentId );
		foreach ( $revertTags as $revertTag ) {
			if ( in_array( $revertTag, $tags ) ) {
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
		if ( self::shouldSkipUser( $permissionManager, $user, $wikiConfig ) ) {
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
	 * @param array $score
	 * @param string $revertRiskModelName
	 * @return array
	 */
	public function maybeRollback( array $score, string $revertRiskModelName ): array {
		$reverted = 0;
		$status = 'Not reverted';
		$probability = $score[ 'output' ][ 'probabilities' ][ 'true' ];
		$wikiPage = $this->wikiPageFactory->newFromID( $this->wikiPageId );
		$rev = $this->revisionStore->getRevisionById( $this->revId );
		// Check if the threshold should be taken from the language-agnostic
		// or the multilingual model based on what model was chosen in the job
		if ( $probability > Util::getRevertThreshold( $this->wikiConfig, $this->config, $revertRiskModelName ) ) {
			$prevRev = $this->revisionStore->getPreviousRevision( $rev );
			$content = $this->getUndoContent( $prevRev, $this->undoSummary, $wikiPage, $rev );
			if ( !$content ) {
				return [ $reverted => $this->undoSummary ];
			}
			if ( $this->enforce ) {
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
						return [ $reverted => $status ];
					}
					return [ $reverted => $errorMessages ?
						wfMessage( $errorMessages[0] )->inLanguage( "en" )->plain()
						: "Failed to save revision" ];
				}
			}
			$reverted = 1;
			$status = 'success';
		}
		return [ $reverted => $status ];
	}

	/**
	 * @param PermissionManager $permissionManager
	 * @param UserIdentity $user
	 * @param Config $wikiConfig
	 * @return bool
	 */
	public static function shouldSkipUser( PermissionManager $permissionManager,
		UserIdentity $user, Config $wikiConfig ): bool {
			return $permissionManager->userHasAnyRight(
				$user, ...(array)$wikiConfig->get( 'AutoModeratorSkipUserRights' )
			);
	}

	/**
	 * @param UserIdentity $user
	 * @param UserIdentity $autoModeratorUser
	 * @return bool
	 */
	public static function areUsersEqual( UserIdentity $user, UserIdentity $autoModeratorUser ): bool {
		return $user->equals( $autoModeratorUser );
	}

	/**
	 * @param RestrictionStore $restrictionStore
	 * @param WikiPage $wikiPage
	 * @return bool
	 */
	public static function isProtectedPage( RestrictionStore $restrictionStore, WikiPage $wikiPage ): bool {
		return $restrictionStore->isProtected( $wikiPage )
			&& !$restrictionStore->isSemiProtected( $wikiPage );
	}

	/**
	 * @param int|null $parentId
	 * @return bool
	 */
	public static function isNewPageCreation( ?int $parentId ): bool {
		return $parentId === null || $parentId === 0;
	}
}
