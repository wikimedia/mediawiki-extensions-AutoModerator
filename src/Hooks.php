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

use AutoModerator\Config\AutoModeratorConfigLoaderStaticTrait;
use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\Config\Config;
use MediaWiki\Content\ContentHandlerFactory;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Page\Hook\RevisionFromEditCompleteHook;
use MediaWiki\Permissions\RestrictionStore;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\User\UserGroupManager;

class Hooks implements
	RevisionFromEditCompleteHook
{
	use AutoModeratorConfigLoaderStaticTrait;

	private ChangeTagsStore $changeTagsStore;

	private Config $config;

	private Config $wikiConfig;

	private ContentHandlerFactory $contentHandlerFactory;

	private RevisionStore $revisionStore;

	private UserGroupManager $userGroupManager;

	private RestrictionStore $restrictionStore;

	/**
	 * @param ChangeTagsStore $changeTagsStore
	 * @param Config $config
	 * @param Config $wikiConfig
	 * @param ContentHandlerFactory $contentHandlerFactory
	 * @param RevisionStore $revisionStore
	 * @param UserGroupManager $userGroupManager
	 * @param RestrictionStore $restrictionStore
	 */
	public function __construct(
		ChangeTagsStore $changeTagsStore,
		Config $config,
		Config $wikiConfig,
		ContentHandlerFactory $contentHandlerFactory,
		RevisionStore $revisionStore,
		UserGroupManager $userGroupManager,
		RestrictionStore $restrictionStore
	) {
		$this->changeTagsStore = $changeTagsStore;
		$this->config = $config;
		$this->wikiConfig = $wikiConfig;
		$this->contentHandlerFactory = $contentHandlerFactory;
		$this->revisionStore = $revisionStore;
		$this->userGroupManager = $userGroupManager;
		$this->restrictionStore = $restrictionStore;
	}

	/**
	 * @inheritDoc
	 */
	public function onRevisionFromEditComplete( $wikiPage, $rev, $originalRevId, $user, &$tags ) {
		if ( !$this->wikiConfig->get( 'AutoModeratorEnableRevisionCheck' ) || !$wikiPage || !$rev || !$user ) {
			return;
		}
		$autoModeratorUser = Util::getAutoModeratorUser();
		$contentHandler = $this->contentHandlerFactory->getContentHandler( $rev->getSlot(
			SlotRecord::MAIN,
			RevisionRecord::RAW
		)->getModel() );
		$logger = LoggerFactory::getInstance( 'AutoModerator' );
		$revisionCheck = new RevisionCheck(
			$wikiPage,
			$rev,
			$originalRevId,
			$user,
			$tags,
			$autoModeratorUser,
			$this->revisionStore,
			$this->changeTagsStore,
			$contentHandler,
			$logger,
			$this->userGroupManager,
			$this->restrictionStore,
			true
		);
		if ( !$revisionCheck->passedPreCheck ) {
			return;
		}
		// @todo replace 'en' with getWikiID()
		$liftWingClient = new LiftWingClient( 'revertrisk-language-agnostic', 'en', $revisionCheck->passedPreCheck );
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
