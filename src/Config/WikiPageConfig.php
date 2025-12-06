<?php

namespace AutoModerator\Config;

use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigException;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use Psr\Log\LoggerInterface;
use RuntimeException;
use StatusValue;

class WikiPageConfig implements Config {

	private ?Title $configTitle = null;

	/**
	 * @param LoggerInterface $logger
	 * @param TitleFactory $titleFactory
	 * @param WikiPageConfigLoader $configLoader
	 * @param bool $isTestWithStorageDisabled Hack to disable DB access in non-database tests.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly TitleFactory $titleFactory,
		private readonly WikiPageConfigLoader $configLoader,
		private readonly bool $isTestWithStorageDisabled,
	) {
	}

	/**
	 * Helper to late-construct Title
	 *
	 * Config is initialized pretty early. This allows us to delay construction of
	 * Title (which may talk to the DB) until whenever config is first fetched,
	 * which should be much later, and probably after init sequence finished.
	 *
	 * @throws RuntimeException
	 * @return Title
	 */
	private function getConfigTitle(): Title {
		if ( $this->configTitle == null ) {
			$configTitle = $this->titleFactory->makeTitleSafe(
				NS_MEDIAWIKI,
				'AutoModeratorConfig.json'
			);
			if (
				$configTitle === null ||
				!$configTitle->isSiteJsonConfigPage()
			) {
				throw new RuntimeException( 'Invalid AutoModeratorWikiConfigPageTitle' );
			}

			$this->configTitle = $configTitle;
		}

		return $this->configTitle;
	}

	/**
	 * Helper function to fetch config data from wiki page
	 *
	 * This may sound expensive, but WikiPageConfigLoader is supposed
	 * to take care about caching.
	 *
	 * @param int $flags bit field, see IDBAccessObject::READ_XXX
	 *
	 * @throws ConfigException on an error
	 * @return array
	 */
	private function getConfigData( int $flags = 0 ): array {
		if ( $this->isTestWithStorageDisabled ) {
			return [];
		}
		if ( !$this->getConfigTitle()->exists() ) {
			// configLoader throws an exception for no-page case
			return [];
		}
		// Load the multilingual configuration page if it exists
		// in order to merge the multilingual configuration into the language agnostic configuration.
		$multilingualConfigPage = $this->titleFactory->makeTitleSafe(
			NS_MEDIAWIKI,
			'AutoModeratorMultilingualConfig.json'
		);
		$multilingualResult = $multilingualConfigPage ? $this->configLoader->load(
			$multilingualConfigPage, $flags ) : [];
		if ( $multilingualResult instanceof StatusValue ) {
			$multilingualResult = [];
		}
		$res = $this->configLoader->load( $this->getConfigTitle(), $flags );
		if ( $res instanceof StatusValue ) {
			// Loading config failed. This can happen in case of both a software error and
			// an error made by an administrator (setting the JSON blob manually to something
			// badly malformed, ie. set an array when a bool is expected). Log the error, and
			// pretend there is nothing in the JSON blob.

			$this->logger->error(
				__METHOD__ . ' failed to load config from wiki: {error}',
				[
					'error' => (string)$res,
					'impact' => 'Config stored in MediaWiki:AutoModeratorConfig.json ' .
						'is ignored, using sane fallbacks instead'
				]
			);

			// Throwing an exception would make _both_ get() and has() throw an exception,
			//  while returning an empty array means has() finishes nicely (with a false),
			//  while get still throws an exception (as calling get with has() returning false is unexpected).
			return [];
		}
		// merge both language agnostic and multilingual configuration
		return array_merge( $res, $multilingualResult );
	}

	/**
	 * @inheritDoc
	 */
	public function get( $name ) {
		return $this->getWithFlags( $name );
	}

	/**
	 * @param string $name
	 * @param int $flags bit field, see IDBAccessObject::READ_XXX
	 * @return mixed Config value
	 */
	public function getWithFlags( string $name, int $flags = 0 ) {
		$configData = $this->getConfigData( $flags );
		if ( !array_key_exists( $name, $configData ) ) {
			throw new ConfigException( 'Config key was not found in WikiPageConfig' );
		}

		return $configData[ $name ];
	}

	/**
	 * @inheritDoc
	 */
	public function has( $name ): bool {
		return $this->hasWithFlags( $name );
	}

	/**
	 * @param string $name
	 * @param int $flags bit field, see IDBAccessObject::READ_XXX
	 * @return bool
	 */
	public function hasWithFlags( $name, int $flags = 0 ): bool {
		return array_key_exists( $name, $this->getConfigData( $flags ) );
	}
}
