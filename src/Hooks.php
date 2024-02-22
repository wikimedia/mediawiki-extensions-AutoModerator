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

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\Hook\RevisionFromEditCompleteHook;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;

class Hooks implements
	RevisionFromEditCompleteHook
	{
	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/RevisionFromEditComplete
	 *
	 * @param \WikiPage $wikiPage WikiPage edited
	 * @param RevisionRecord $rev New revision
	 * @param int|false $originalRevId If the edit restores or repeats an earlier revision (such as a
	 *   rollback or a null revision), the ID of that earlier revision. False otherwise.
	 *   (Used to be called $baseID.)
	 * @param \MediaWiki\User\UserIdentity $user Editing user
	 * @param string[] &$tags Tags to apply to the edit and recent change. This is empty, and
	 *   replacement is ignored, in the case of import or page move.
	 *
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public function onRevisionFromEditComplete( $wikiPage, $rev, $originalRevId, $user, &$tags ) {
	$config = MediaWikiServices::getInstance()->getMainConfig();

		if ( $config->get( 'AutoModeratorEnable' ) ) {
			// @todo replace 'en' with getWikiID()
			$autoModeratorUser = Util::getUser();
			$revisionStore = MediaWikiServices::getInstance()->getRevisionStoreFactory()->getRevisionStore();
			$changeTagsStore = MediaWikiServices::getInstance()->getChangeTagsStore();
			$contentHandler = MediaWikiServices::getInstance()->getContentHandlerFactory()
				->getContentHandler( $rev->getSlot(
						SlotRecord::MAIN,
						RevisionRecord::RAW
				)->getModel() );
			$logger = LoggerFactory::getInstance( 'AutoModerator' );
			$userGroupManager = MediaWikiServices::getInstance()->getUserGroupManager();
			$revisionCheck = new RevisionCheck(
				$wikiPage,
				$rev,
				$originalRevId,
				$user,
				$tags,
				$autoModeratorUser,
				$revisionStore,
				$changeTagsStore,
				$contentHandler,
				$logger,
				$userGroupManager
			);
			$revisionCheck->revertPreCheck();
			$passedPreCheck = $revisionCheck->getPassedPreCheck();
			$liftWingClient = new LiftWingClient( 'revertrisk-language-agnostic', 'en', $passedPreCheck );
			if ( $passedPreCheck ) {
				// Wrap in a POSTSEND deferred update to avoid blocking the HTTP response
				DeferredUpdates::addCallableUpdate( static function () use (
					$liftWingClient,
					$revisionCheck,
					$rev
				) {
					$score = $liftWingClient->get( $rev->getId() );
					$revisionCheck->maybeRevert( $score );
				} );
			}
		}
	}
}
