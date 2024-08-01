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
use AutoModerator\Hooks\RevisionFromEditCompleteHookHandler;
use JobQueueGroup;
use MediaWiki\Config\Config;
use MediaWiki\Hook\HistoryToolsHook;
use MediaWiki\Html\Html;
use MediaWiki\Page\Hook\RevisionFromEditCompleteHook;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Permissions\RestrictionStore;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\UserGroupManager;

class Hooks implements
	RevisionFromEditCompleteHook,
	HistoryToolsHook
{
	use AutoModeratorConfigLoaderStaticTrait;

	private Config $wikiConfig;

	private UserGroupManager $userGroupManager;

	private Config $config;

	private WikiPageFactory $wikiPageFactory;

	private RevisionStore $revisionStore;

	private RestrictionStore $restrictionStore;

	private JobQueueGroup $jobQueueGroup;

	private TitleFactory $titleFactory;

	/**
	 * @param Config $wikiConfig
	 * @param UserGroupManager $userGroupManager
	 * @param Config $config
	 * @param WikiPageFactory $wikiPageFactory
	 * @param RevisionStore $revisionStore
	 * @param RestrictionStore $restrictionStore
	 * @param JobQueueGroup $jobQueueGroup
	 * @param TitleFactory $titleFactory
	 */
	public function __construct( Config $wikiConfig, UserGroupManager $userGroupManager, Config $config,
		WikiPageFactory $wikiPageFactory, RevisionStore $revisionStore, RestrictionStore $restrictionStore,
		JobQueueGroup $jobQueueGroup, TitleFactory $titleFactory ) {
			$this->wikiConfig = $wikiConfig;
			$this->userGroupManager = $userGroupManager;
			$this->config = $config;
			$this->wikiPageFactory = $wikiPageFactory;
			$this->revisionStore = $revisionStore;
			$this->restrictionStore = $restrictionStore;
			$this->jobQueueGroup = $jobQueueGroup;
			$this->titleFactory = $titleFactory;
	}

	/**
	 * @inheritDoc
	 */
	public function onRevisionFromEditComplete( $wikiPage, $rev, $originalRevId, $user, &$tags ) {
		$handler = new RevisionFromEditCompleteHookHandler( $this->wikiConfig,
			$this->userGroupManager, $this->config, $this->wikiPageFactory, $this->revisionStore,
			$this->restrictionStore, $this->jobQueueGroup );
		$handler->handle( $wikiPage, $rev, $originalRevId, $user, $tags );
	}

	/**
	 * @inheritDoc
	 */
	public function onHistoryTools( $revRecord, &$links, $prevRevRecord, $userIdentity ) {
		$revUser = $revRecord->getUser();
		$falsePositivePageText = $this->wikiConfig->get( 'AutoModeratorFalsePositivePageTitle' );
		if ( $revUser === null || $falsePositivePageText === null ) {
			// Cannot see the user or the false positive page isn't configured
			return;
		}
		$autoModeratorUser = Util::getAutoModeratorUser( $this->config, $this->userGroupManager );
		$falsePositivePageTitle = $this->titleFactory->newFromText( $falsePositivePageText );
		if ( $falsePositivePageTitle === null ) {
			// The false positive page title has been configured, but the page has not been created
			return;
		}
		$falsePositivePageUrl = $falsePositivePageTitle->getFullURL();
		// Only add the report link if it's an AutoModerator revert
		if ( $autoModeratorUser->getId() === $revUser->getId() ) {
			$links[] = Html::element(
				'a',
				[
					'class' => 'mw-automoderator-report-link',
					'href' => $falsePositivePageUrl,
					'title' => wfMessage( 'automoderator-wiki-report-false-positive' )->text(),
				],
				wfMessage( 'automoderator-wiki-report-false-positive' )->text()
			);
		}
	}
}
