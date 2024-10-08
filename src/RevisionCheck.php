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

use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Config\Config;
use MediaWiki\Content\Content;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Language\Language;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Permissions\RestrictionStore;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Storage\EditResult;
use MediaWiki\Storage\PageUpdater;
use MediaWiki\Storage\PageUpdateStatus;
use MediaWiki\StubObject\StubUserLang;
use MediaWiki\User\ExternalUserNames;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;
use Psr\Log\LoggerInterface;
use WikiPage;

class RevisionCheck {

	/** @var int */
	private $wikiPageId;

	/** @var WikiPageFactory */
	private $wikiPageFactory;

	/** @var int */
	private $revId;

	/** @var User */
	private $autoModeratorUser;

	/** @var RevisionStore */
	private $revisionStore;

	/** @var Config */
	private $config;

	/** @var Config */
	private $wikiConfig;

	/** @var string */
	public string $undoSummary;

	/** @var ContentHandler */
	private $contentHandler;

	/** @var bool */
	private bool $enforce;

	/** @var Language|StubUserLang|string */
	private $lang;

	/**
	 * @param int $wikiPageId WikiPage ID of
	 * @param WikiPageFactory $wikiPageFactory
	 * @param int $revId New revision ID
	 * @param User $autoModeratorUser reverting user
	 * @param RevisionStore $revisionStore
	 * @param Config $config
	 * @param Config $wikiConfig
	 * @param ContentHandler $contentHandler
	 * @param Language|StubUserLang|string $lang
	 * @param string $undoSummary
	 * @param bool $enforce Perform reverts if true, take no action if false
	 */
	public function __construct(
		int $wikiPageId,
		WikiPageFactory $wikiPageFactory,
		int $revId,
		User $autoModeratorUser,
		RevisionStore $revisionStore,
		Config $config,
		$wikiConfig,
		ContentHandler $contentHandler,
		$lang,
		string $undoSummary,
		bool $enforce = false
	) {
		$this->wikiPageId = $wikiPageId;
		$this->wikiPageFactory = $wikiPageFactory;
		$this->revId = $revId;
		$this->autoModeratorUser = $autoModeratorUser;
		$this->revisionStore = $revisionStore;
		$this->config = $config;
		$this->wikiConfig = $wikiConfig;
		$this->contentHandler = $contentHandler;
		$this->enforce = $enforce;
		$this->lang = $lang;
		$this->undoSummary = $undoSummary;
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
		&$error,
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
			RevisionStore $revisionStore, array $tags, RestrictionStore $restrictionStore,
			WikiPageFactory $wikiPageFactory, Config $wikiConfig, int $revId, int $wikiPageId,
			PermissionManager $permissionManager ): bool {
		// Skips reverts if AutoModerator is blocked
		$autoModeratorBlock = $autoModeratorUser->getBlock();
		if ( $autoModeratorBlock && $autoModeratorBlock->appliesToPage( $wikiPageId ) ) {
			$logger->debug( "AutoModerator skip rev" . __METHOD__ . " - AutoModerator is blocked" );
			return false;
		}
		// Skip AutoModerator edits
		if ( self::areUsersEqual( $user, $autoModeratorUser ) ) {
			$logger->debug( "AutoModerator skip rev" . __METHOD__ . " - AutoMod edits" );
			return false;
		}
		$parentId = $revisionStore->getRevisionById( $revId )->getParentId();
		// Skip new page creations
		if ( self::isNewPageCreation( $parentId ) ) {
			$logger->debug( "AutoModerator skip rev" . __METHOD__ . " - new page creation" );
			return false;
		}
		// Skip reverts made to an AutoModerator bot revert or if
		// the user reverts their own edit
		$revertTags = [ 'mw-manual-revert', 'mw-rollback', 'mw-undo', 'mw-reverted' ];
		$parentRev = $revisionStore->getRevisionById( $parentId );
		foreach ( $revertTags as $revertTag ) {
			if ( in_array( $revertTag, $tags ) ) {
				if ( !$parentRev ) {
					$logger->debug( "AutoModerator skip rev" . __METHOD__ . " - parent revision not found" );
					return false;
				}
				$parentRevUser = $parentRev->getUser();
				if ( $parentRevUser === null ) {
					$logger->debug( "AutoModerator skip rev" . __METHOD__ . " - parent revision user is null" );
					return false;
				}
				if ( self::areUsersEqual( $parentRevUser, $autoModeratorUser ) ) {
					$logger->debug( "AutoModerator skip rev" . __METHOD__ . " - AutoModerator reverts" );
					return false;
				}
				if ( self::areUsersEqual( $parentRevUser, $user ) ) {
					$logger->debug( "AutoModerator skip rev" . __METHOD__ . " - own reverts" );
					return false;
				}
			}
		}
		// Skip page moves
		$moveTags = [ 'mw-new-redirect', 'mw-removed-redirect', 'mw-changed-redirect-target' ];
		foreach ( $moveTags as $moveTag ) {
			if ( in_array( $moveTag, $tags ) ) {
				return false;
			}
		}
		// Skip edits from editors that have certain user rights
		if ( self::shouldSkipUser( $permissionManager, $user, $wikiConfig ) ) {
			$logger->debug( "AutoModerator skip rev" . __METHOD__ . " - trusted user rights edits" );
			return false;
		}
		// Skip external users
		if ( ExternalUserNames::isExternal( $user->getName() ) ) {
			$logger->debug( "AutoModerator skip rev" . __METHOD__ . " - external user" );
			return false;
		}
		$wikiPage = $wikiPageFactory->newFromID( $wikiPageId );
		// Skip null pages
		if ( $wikiPage === null ) {
			$logger->debug( "AutoModerator skip rev" . __METHOD__ . " - wikiPage is null" );
			return false;
		}
		// Skip non-mainspace edit
		if ( $wikiPage->getNamespace() !== NS_MAIN ) {
			$logger->debug( "AutoModerator skip rev" . __METHOD__ . " - non-mainspace edits" );
			return false;
		}
		// Skip protected pages that only admins can edit.
		// Automoderator should be able to revert semi-protected pages,
		// so we won't be skipping those on pre-check.
		if ( self::isProtectedPage( $restrictionStore, $wikiPage ) ) {
			$logger->debug( "AutoModerator skip rev" . __METHOD__ . " - protected page" );
			return false;
		}
		return true;
	}

	/**
	 * Perform revert
	 * @param PageUpdater $pageUpdater
	 * @param Content $content
	 * @param RevisionRecord $prevRev
	 */
	private function doRevert( $pageUpdater, $content, $prevRev ): PageUpdateStatus {
		$pageUpdater->setContent( SlotRecord::MAIN, $content );
		$pageUpdater->setOriginalRevisionId( $prevRev->getId() );
		$comment = CommentStoreComment::newUnsavedComment( $this->undoSummary );
		$pageUpdater->markAsRevert( EditResult::REVERT_UNDO, $this->revId, $prevRev->getId() );
		if ( $this->wikiConfig->get( 'AutoModeratorUseEditFlagMinor' ) ) {
			$pageUpdater->setFlags( EDIT_MINOR );
		}
		if ( $this->wikiConfig->get( 'AutoModeratorEnableBotFlag' ) ) {
			$pageUpdater->setFlags( EDIT_FORCE_BOT );
		}
		$pageUpdater->saveRevision( $comment, EDIT_UPDATE );
		return $pageUpdater->getStatus();
	}

	/**
	 * Check revision; revert if it meets configured critera
	 * @param array $score
	 *
	 * @return array
	 */
	public function maybeRevert( $score ) {
		$reverted = 0;
		$status = 'Not reverted';
		$probability = $score[ 'output' ][ 'probabilities' ][ 'true' ];
		$wikiPage = $this->wikiPageFactory->newFromID( $this->wikiPageId );
		$rev = $this->revisionStore->getRevisionById( $this->revId );
		// Automoderator system user may perform updates
		$pageUpdater = $wikiPage->newPageUpdater( $this->autoModeratorUser );
		if ( $probability > Util::getRevertThreshold( $this->wikiConfig ) ) {
			$prevRev = $this->revisionStore->getPreviousRevision( $rev );
			$content = $this->getUndoContent( $prevRev, $this->undoSummary, $wikiPage, $rev );
			if ( !$content ) {
				return [ $reverted => $this->undoSummary ];
			}
			if ( $this->enforce ) {
				$pageUpdateStatus = $this->doRevert( $pageUpdater, $content, $prevRev );
				if ( !$pageUpdateStatus->isOK() ) {
					$errorMessages = $pageUpdateStatus->getMessages( 'error' );
					return [ $reverted => $errorMessages ? wfMessage( $errorMessages[0] )->inLanguage( "en" )->plain()
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
