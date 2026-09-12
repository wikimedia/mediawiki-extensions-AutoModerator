<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\AutoModerator;

use MediaWiki\Actions\Hook\HistoryToolsHook;
use MediaWiki\Config\Config;
use MediaWiki\Html\Html;
use MediaWiki\Title\TitleFactory;

readonly class Hooks implements HistoryToolsHook {

	public function __construct(
		private Config $config,
		private TitleFactory $titleFactory,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function onHistoryTools( $revRecord, &$links, $prevRevRecord, $userIdentity ): void {
		$revUser = $revRecord->getUser();
		// Only add the report link if the user can be seen and it's an AutoModerator revert
		if ( $revUser === null || $this->config->get( 'AutoModeratorUsername' ) !== $revUser->getName() ) {
			return;
		}

		$falsePositivePageText = Util::getFalsePositivePageTitleText( $this->config );
		if ( $falsePositivePageText === null ) {
			// The false positive page isn't configured
			return;
		}
		$falsePositivePageTitle = $this->titleFactory->newFromText( $falsePositivePageText );
		if ( $falsePositivePageTitle === null ) {
			// The false positive page title has been configured but is not a valid title
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
