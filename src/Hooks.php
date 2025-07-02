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

use MediaWiki\Config\Config;
use MediaWiki\Hook\HistoryToolsHook;
use MediaWiki\Html\Html;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\UserGroupManager;

class Hooks implements
	HistoryToolsHook
{

	private Config $wikiConfig;

	private UserGroupManager $userGroupManager;

	private Config $config;

	private TitleFactory $titleFactory;

	/**
	 * @param Config $wikiConfig
	 * @param UserGroupManager $userGroupManager
	 * @param Config $config
	 * @param TitleFactory $titleFactory
	 */
	public function __construct(
		Config $wikiConfig,
		UserGroupManager $userGroupManager,
		Config $config,
		TitleFactory $titleFactory
	) {
		$this->wikiConfig = $wikiConfig;
		$this->userGroupManager = $userGroupManager;
		$this->config = $config;
		$this->titleFactory = $titleFactory;
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
		// Add parameters to false positive page
		$falsePositivePreloadTemplate = $falsePositivePageTitle . '/Preload';
		$pageTitle = $this->titleFactory->newFromPageIdentity( $revRecord->getPage() );
		$falsePositiveParams = '?action=edit&section=new&nosummary=true&preload=' . $falsePositivePreloadTemplate .
			'&preloadparams[]=' . $revRecord->getId() . '&preloadparams[]=' . $pageTitle;
		// Only add the report link if it's an AutoModerator revert
		if ( $autoModeratorUser->getId() === $revUser->getId() ) {
			$links[] = Html::element(
				'a',
				[
					'class' => 'mw-automoderator-report-link',
					'href' => $falsePositivePageUrl . $falsePositiveParams,
					'title' => wfMessage( 'automoderator-wiki-report-false-positive' )->text(),
				],
				wfMessage( 'automoderator-wiki-report-false-positive' )->text()
			);
		}
	}
}
