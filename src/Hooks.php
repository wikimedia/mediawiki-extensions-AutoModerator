<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\AutoModerator;

use MediaWiki\Actions\Hook\HistoryToolsHook;
use MediaWiki\Config\Config;
use MediaWiki\Html\Html;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\UserGroupManager;

readonly class Hooks implements HistoryToolsHook {

	public function __construct(
		private UserGroupManager $userGroupManager,
		private Config $config,
		private TitleFactory $titleFactory,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function onHistoryTools( $revRecord, &$links, $prevRevRecord, $userIdentity ): void {
		$revUser = $revRecord->getUser();

		$falsePositivePageText = Util::getFalsePositivePageTitleText( $this->config );
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
		// Add parameters to false positive page
		$falsePositivePreloadTemplate = $falsePositivePageTitle->getPrefixedDBkey() . '/Preload';
		$pageTitle = $this->titleFactory->newFromPageIdentity( $revRecord->getPage() )->getDBkey();
		$falsePositiveParams = [
			'action' => 'edit',
			'section' => 'new',
			'nosummary' => 'true',
			'preload' => $falsePositivePreloadTemplate,
			'preloadparams' => [ $revRecord->getId(), $pageTitle ],
		];
		// Only add the report link if it's an AutoModerator revert
		if ( $autoModeratorUser->getId() === $revUser->getId() ) {
			$links[] = Html::element(
				'a',
				[
					'class' => 'mw-automoderator-report-link',
					'href' => $falsePositivePageTitle->getFullURL( $falsePositiveParams ),
					'title' => wfMessage( 'automoderator-wiki-report-false-positive' )->text(),
				],
				wfMessage( 'automoderator-wiki-report-false-positive' )->text()
			);
		}
	}
}
