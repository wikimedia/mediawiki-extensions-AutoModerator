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
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\User\UserGroupManager;

class Hooks implements
	RevisionFromEditCompleteHook
{
	use AutoModeratorConfigLoaderStaticTrait;

	/** @var ChangeTagsStore */
	private $changeTagsStore;

	/** @var Config */
	private $config;

	/** @var Config */
	private $wikiConfig;

	/** @var ContentHandlerFactory */
	private $contentHandlerFactory;

	/** @var RevisionStore */
	private $revisionStore;

	/** @var UserGroupManager */
	private $userGroupManager;

	/**
	 * @param ChangeTagsStore $changeTagsStore
	 * @param Config $config
	 * @param Config $wikiConfig
	 * @param ContentHandlerFactory $contentHandlerFactory
	 * @param revisionStore $revisionStore
	 * @param userGroupManager $userGroupManager
	 */
	public function __construct(
		ChangeTagsStore $changeTagsStore,
		Config $config,
		Config $wikiConfig,
		ContentHandlerFactory $contentHandlerFactory,
		RevisionStore $revisionStore,
		UserGroupManager $userGroupManager
	) {
		$this->changeTagsStore = $changeTagsStore;
		$this->config = $config;
		$this->wikiConfig = $wikiConfig;
		$this->contentHandlerFactory = $contentHandlerFactory;
		$this->revisionStore = $revisionStore;
		$this->userGroupManager = $userGroupManager;
	}

	/**
	 * @inheritDoc
	 */
	public function onRevisionFromEditComplete( $wikiPage, $rev, $originalRevId, $user, &$tags ) {
		if ( !$this->wikiConfig->get( 'AutoModeratorEnable' ) ) {
			return;
		}
		if ( !$wikiPage || !$rev || !$user ) {
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
