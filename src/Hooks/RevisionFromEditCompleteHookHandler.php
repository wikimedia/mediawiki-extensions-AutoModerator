<?php
/**
 * Copyright (C) 2016 Brad Jorsch <bjorsch@wikimedia.org>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace AutoModerator\Hooks;

use AutoModerator\Services\AutoModeratorFetchRevScoreJob;
use AutoModerator\Util;
use Exception;
use MediaWiki\Config\Config;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentity;
use WikiPage;

class RevisionFromEditCompleteHookHandler {

	private Config $wikiConfig;

	private UserGroupManager $userGroupManager;

	private Config $config;

	/**
	 * @param Config $wikiConfig
	 * @param UserGroupManager $userGroupManager
	 * @param Config $config
	 */
	public function __construct( Config $wikiConfig, UserGroupManager $userGroupManager, Config $config ) {
		$this->wikiConfig = $wikiConfig;
		$this->userGroupManager = $userGroupManager;
		$this->config = $config;
	}

	/**
	 * @param WikiPage $wikiPage
	 * @param RevisionRecord $rev
	 * @param int|false $originalRevId
	 * @param UserIdentity $user
	 * @param string[] &$tags
	 */
	public function handle(
		$wikiPage, $rev, $originalRevId, $user, &$tags
	) {
		$autoModeratorUser = Util::getAutoModeratorUser( $this->config, $this->userGroupManager );
		if ( !$this->wikiConfig->get( 'AutoModeratorEnableRevisionCheck' ) || !$wikiPage || !$rev || !$user
			|| $autoModeratorUser->getId() === $user->getId() ) {
			return;
		}

		$logger = LoggerFactory::getInstance( 'AutoModerator' );
		$title = $wikiPage->getTitle();
		$wikiPageId = $wikiPage->getId();
		$revId = $rev->getId();
		$job = new AutoModeratorFetchRevScoreJob( $title,
			[
				'wikiPageId' => $wikiPageId,
				'revId' => $revId,
				'originalRevId' => $originalRevId,
				'user' => $user,
				'tags' => $tags
			]
		);
		try {
			MediaWikiServices::getInstance()->getJobQueueGroup()->push( $job );
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
