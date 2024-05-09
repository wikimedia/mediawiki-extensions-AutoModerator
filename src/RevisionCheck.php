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

use CommentStoreComment;
use Content;
use ContentHandler;
use Language;
use MediaWiki\Config\Config;
use MediaWiki\Language\RawMessage;
use MediaWiki\MainConfigNames;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Permissions\RestrictionStore;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Storage\PageUpdater;
use MediaWiki\StubObject\StubUserLang;
use MediaWiki\User\ExternalUserNames;
use MediaWiki\User\User;
use MediaWiki\User\UserGroupManager;
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

	/** @var int|false */
	private $originalRevId;

	/** @var UserIdentity */
	private $user;

	/** @var string[] */
	private $tags;

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

	/** @var LoggerInterface */
	private $logger;

	/** @var UserGroupManager */
	private $userGroupManager;

	/** @var RestrictionStore */
	private $restrictionStore;

	/** @var bool */
	private bool $enforce;

	/** @var Language|StubUserLang|string */
	private $lang;

	/** @var bool */
	public bool $passedPreCheck;

	/**
	 * @param int $wikiPageId WikiPage ID of
	 * @param WikiPageFactory $wikiPageFactory
	 * @param int $revId New revision ID
	 * @param int|false $originalRevId If the edit restores or repeats an earlier revision (such as a
	 *   rollback or a null revision), the ID of that earlier revision. False otherwise.
	 *   (Used to be called $baseID.)
	 * @param UserIdentity $user Editing user
	 * @param string[] &$tags Tags applied to the revison.
	 * @param User $autoModeratorUser reverting user
	 * @param RevisionStore $revisionStore
	 * @param Config $config
	 * @param Config $wikiConfig
	 * @param ContentHandler $contentHandler
	 * @param LoggerInterface $logger
	 * @param UserGroupManager $userGroupManager
	 * @param RestrictionStore $restrictionStore
	 * @param Language|StubUserLang|string $lang
	 * @param bool $enforce Perform reverts if true, take no action if false
	 */
	public function __construct(
		int $wikiPageId,
		WikiPageFactory $wikiPageFactory,
		int $revId,
		$originalRevId,
		UserIdentity $user,
		array &$tags,
		User $autoModeratorUser,
		RevisionStore $revisionStore,
		Config $config,
		$wikiConfig,
		ContentHandler $contentHandler,
		LoggerInterface $logger,
		UserGroupManager $userGroupManager,
		RestrictionStore $restrictionStore,
		$lang,
		bool $enforce = false
	) {
		$this->wikiPageId = $wikiPageId;
		$this->wikiPageFactory = $wikiPageFactory;
		$this->revId = $revId;
		$this->originalRevId = $originalRevId;
		$this->user = $user;
		$this->tags = $tags;
		$this->autoModeratorUser = $autoModeratorUser;
		$this->revisionStore = $revisionStore;
		$this->config = $config;
		$this->wikiConfig = $wikiConfig;
		$this->contentHandler = $contentHandler;
		$this->logger = $logger;
		$this->userGroupManager = $userGroupManager;
		$this->restrictionStore = $restrictionStore;
		$this->enforce = $enforce;
		$this->lang = $lang;
		$this->passedPreCheck = $this->revertPreCheck();
		$this->undoSummary = '';
	}

	/**
	 * @return void
	 */
	public function setUndoSummary() {
		$userIsAnon = !$this->user->isRegistered();
		$undoMessage = ( $userIsAnon && $this->config->get( MainConfigNames::DisableAnonTalk ) ) ?
			$this->wikiConfig->get( 'AutoModeratorUndoSummaryAnon' ) :
			$this->wikiConfig->get( 'AutoModeratorUndoSummary' );
		$undoSummary = new RawMessage(
			$undoMessage
		);
		$undoSummary->params( [
			$this->revId,
			$this->user->getName()
		] );
		$this->undoSummary = $undoSummary->inLanguage( $this->lang )->plain();
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
	 *
	 * @return bool
	 */
	public function revertPreCheck() {
		// Skip AutoModerator edits
		if ( $this->user->equals( $this->autoModeratorUser ) ) {
			$this->logger->debug( "AutoModerator skip rev" . __METHOD__ . " - AutoMod edits" );
			return false;
		}
		$rev = $this->revisionStore->getRevisionById( $this->revId );
		$parentId = $rev->getParentId();
		// Skip new page creations
		if ( $parentId === null || $parentId === 0 ) {
			$this->logger->debug( "AutoModerator skip rev" . __METHOD__ . " - new page creation" );
			return false;
		}
		$parentRev = $this->revisionStore->getRevisionById( $parentId );
		// Skip reverts made to an AutoModerator bot revert or if
		// the user reverts their own edit
		$revertTags = [ 'mw-manual-revert', 'mw-rollback', 'mw-undo', 'mw-reverted' ];
		foreach ( $revertTags as $revertTag ) {
			if ( in_array( $revertTag, $this->tags ) ) {
				if ( $parentRev->getUser()->equals( $this->autoModeratorUser ) ) {
					$this->logger->debug( "AutoModerator skip rev" . __METHOD__ . " - AutoModerator reverts" );
					return false;
				}
				if ( $parentRev->getUser()->equals( $this->user ) ) {
					$this->logger->debug( "AutoModerator skip rev" . __METHOD__ . " - own reverts" );
					return false;
				}
			}
		}
		// Skip page moves
		$moveTags = [ 'mw-new-redirect', 'mw-removed-redirect', 'mw-changed-redirect-target' ];
		foreach ( $moveTags as $moveTag ) {
			if ( in_array( $moveTag, $this->tags ) ) {
				return false;
			}
		}
		// Skip sysop and bot user edits
		// @todo: Move bot skip to check on recent changes rc_bot field
		$skipGroups = [ 'sysop', 'bot' ];
		$userGroups = $this->userGroupManager->getUserGroupMemberships( $this->user );
		foreach ( $skipGroups as $skipGroup ) {
			if ( array_key_exists( $skipGroup, $userGroups ) ) {
				$this->logger->debug( "AutoModerator skip rev" . __METHOD__ . " - sysop or bot edits" );
				return false;
			}
		}
		// Skip imported revisions
		if ( ExternalUserNames::isExternal( $this->user->getName() ) ) {
			$this->logger->debug( "AutoModerator skip rev" . __METHOD__ . " - imported edits" );
			return false;
		}
		$wikiPage = $this->wikiPageFactory->newFromID( $this->wikiPageId );
		// Skip non-mainspace edit
		if ( $wikiPage->getNamespace() !== NS_MAIN ) {
			$this->logger->debug( "AutoModerator skip rev" . __METHOD__ . " - non-mainspace edits" );
			return false;
		}
		// Skip protected pages that only admins can edit.
		// Automoderator should be able to revert semi-protected pages,
		// so we won't be skipping those on pre-check.
		if ( $this->restrictionStore->isProtected( $wikiPage )
				&& !$this->restrictionStore->isSemiProtected( $wikiPage ) ) {
			$this->logger->debug( "AutoModerator skip rev" . __METHOD__ . " - protected page" );
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
	private function doRevert( $pageUpdater, $content, $prevRev ) {
		$pageUpdater->setContent( SlotRecord::MAIN, $content );
		$pageUpdater->setOriginalRevisionId( $prevRev->getId() );
		$comment = CommentStoreComment::newUnsavedComment( $this->undoSummary );
		// REVERT_UNDO 1
		// REVERT_ROLLBACK 2
		// REVERT_MANUAL 3
		$pageUpdater->markAsRevert( 1, $this->revId, $prevRev->getId() );
		// EDIT_NEW 1
		// EDIT_UPDATE 2
		// EDIT_MINOR 3
		// EDIT_SUPPRESS_RC 4
		// EDIT_FORCE_BOT 5
		// EDIT_AUTOSUMMARY 6
		// EDIT_INTERNAL 7
		$pageUpdater->saveRevision( $comment, 2 );
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
		if ( $probability > Util::getRevertThreshold( $this->config ) ) {
			$prevRev = $this->revisionStore->getPreviousRevision( $rev );
			$this->setUndoSummary();
			$content = $this->getUndoContent( $prevRev, $this->undoSummary, $wikiPage, $rev );
			if ( !$content ) {
				return [ $reverted => $this->undoSummary ];
			}
			if ( $this->enforce ) {
				$this->doRevert( $pageUpdater, $content, $prevRev );
			}
			$reverted = 1;
			$status = 'success';
		}
		return [ $reverted => $status ];
	}
}
