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

namespace MediaWiki\Extension\AutoModerator;

use CommentStoreComment;
use ContentHandler;
use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;

class RevisionCheck {

	/** @var \WikiPage */
	private $wikiPage;

	/** @var RevisionRecord */
	private $rev;

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

	 /** @var ChangeTagsStore */
	private $changeTagsStore;

	/** @var ContentHandler */
	private $contentHandler;

	/** @var \Psr\Log\LoggerInterface */
	private $logger;

	/**
	 * @param \WikiPage $wikiPage WikiPage edited
	 * @param RevisionRecord $rev New revision
	 * @param int|false $originalRevId If the edit restores or repeats an earlier revision (such as a
	 *   rollback or a null revision), the ID of that earlier revision. False otherwise.
	 *   (Used to be called $baseID.)
	 * @param UserIdentity $user Editing user
	 * @param string[] &$tags Tags to apply to the edit and recent change. This is empty, and
	 *   replacement is ignored, in the case of import or page move.
	 * @param User $autoModeratorUser reverting user
	 * @param RevisionStore $revisionStore
	 * @param ChangeTagsStore $changeTagsStore
	 * @param ContentHandler $contentHandler
	 * @param \Psr\Log\LoggerInterface $logger
	 */
	public function __construct(
		\WikiPage $wikiPage,
		RevisionRecord $rev,
		$originalRevId,
		UserIdentity $user,
		array &$tags,
		User $autoModeratorUser,
		RevisionStore $revisionStore,
		ChangeTagsStore $changeTagsStore,
		ContentHandler $contentHandler,
		\Psr\Log\LoggerInterface $logger
	) {
		$this->wikiPage = $wikiPage;
		$this->rev = $rev;
		$this->originalRevId = $originalRevId;
		$this->user = $user;
		$this->tags = $tags;
		$this->autoModeratorUser = $autoModeratorUser;
		$this->revisionStore = $revisionStore;
		$this->changeTagsStore = $changeTagsStore;
		$this->contentHandler = $contentHandler;
		$this->logger = $logger;
	}

		/**
		 * Cribbed from EditPage.php
		 * Returns the result of a three-way merge when undoing changes.
		 *
		 * @param \MediaWiki\Revision\RevisionRecord $oldRev Revision that is being restored. Corresponds to
		 *        `undoafter` URL parameter.
		 * @param ?string &$error If false is returned, this will be set to "norev"
		 *   if the revision failed to load, or "failure" if the content handler
		 *   failed to merge the required changes.
		 *
		 * @return false|\Content
		 */
		private function getUndoContent(
			\MediaWiki\Revision\RevisionRecord $oldRev,
			&$error
		) {
			$currentContent = $this->wikiPage->getRevisionRecord()
				->getContent( SlotRecord::MAIN );
			$undoContent = $this->rev->getContent( SlotRecord::MAIN );
			$undoAfterContent = $oldRev->getContent( SlotRecord::MAIN );
			$undoIsLatest = $this->wikiPage->getRevisionRecord()->getId() === $this->rev->getId();
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
	 * Check revision; revert if it meets configured critera
	 * @param array $score
	 *
	 * @return bool
	 */
	public function maybeRevert( $score ) {
		$reverted = false;
		// Skip null edits
		if ( $this->originalRevId ) {
			return $reverted;
		}
		// Skip edits with known tags; eg. reverts
		$skipTags = [ 'mw-manual-revert', 'mw-rollback', 'mw-undo' ];
		foreach ( $skipTags as $skipTag ) {
			if ( in_array( $skipTag, $this->tags ) ) {
				$this->logger->debug( "AutoModerator skip rev" . __METHOD__ );
				return $reverted;
			}
		}
		// Skip AutoModerator edits
		if ( $this->user->equals( $this->autoModeratorUser ) ) {
			$this->logger->debug( "AutoModerator skip rev" . __METHOD__ );
			return $reverted;
		}
		$probability = $score[ 'output' ][ 'probabilities' ][ 'true' ];
		// Automoderator system user may perform updates
		$pageUpdater = $this->wikiPage->newPageUpdater( $this->autoModeratorUser );
		// @todo use configuration instead of hardcoding
		if ( $probability > 0.5 ) {
			$prevRev = $this->revisionStore->getPreviousRevision( $this->rev );
			// @todo use i18n instead of hardcoded message
			$undoMsg = 'AutoModerator Revert with probability ' . $probability . '.';
			// @todo add conflict detection and resolution in case further edits were made after rev.
			// for conflict detection see the appropriate section of:
			// EditPage::internalAttemptSave
			// https://gerrit.wikimedia.org/r/plugins/gitiles/mediawiki/core/+/refs/heads/master/includes/editpage/EditPage.php#2236
			// for conflict resolution see:
			// EditPage::mergeChangesIntoContent
			// https://gerrit.wikimedia.org/r/plugins/gitiles/mediawiki/core/+/refs/heads/master/includes/editpage/EditPage.php#2678
			$content = $this->getUndoContent( $prevRev, $undoMsg );
			if ( !$content ) {
				return $reverted;
			}
			$pageUpdater->setContent( SlotRecord::MAIN, $content );
			$pageUpdater->setOriginalRevisionId( $prevRev->getId() );
			$comment = CommentStoreComment::newUnsavedComment( $undoMsg );
			// REVERT_UNDO 1
			// REVERT_ROLLBACK 2
			// REVERT_MANUAL 3
			$pageUpdater->markAsRevert( 1, $this->rev->getId(), $prevRev->getId() );
			$this->tags[] = 'ext-automoderator-failed';
			// EDIT_NEW 1
			// EDIT_UPDATE 2
			// EDIT_MINOR 3
			// EDIT_SUPPRESS_RC 4
			// EDIT_FORCE_BOT 5
			// EDIT_AUTOSUMMARY 6
			// EDIT_INTERNAL 7
			$pageUpdater->saveRevision( $comment, 2 );
			$reverted = true;
		} else {
			$this->tags[] = 'ext-automoderator-passed';
		}
		$this->changeTagsStore->addTags( $this->tags, null, $this->rev->getId() );
		return $reverted;
	}
}
