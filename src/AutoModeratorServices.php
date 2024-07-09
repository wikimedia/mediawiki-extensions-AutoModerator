<?php

namespace AutoModerator;

use AutoModerator\Config\Validation\ConfigValidatorFactory;
use AutoModerator\Config\WikiPageConfig;
use AutoModerator\Config\WikiPageConfigLoader;
use MediaWiki\Config\Config;
use MediaWiki\MediaWikiServices;

/**
 * A simple wrapper for MediaWikiServices, to support type safety when accessing
 * services defined by this extension.
 */
class AutoModeratorServices {

	private MediaWikiServices $coreServices;

	/**
	 * @param MediaWikiServices $coreServices
	 */
	public function __construct( MediaWikiServices $coreServices ) {
		$this->coreServices = $coreServices;
	}

	/**
	 * Static version of the constructor, for nicer syntax.
	 * @param MediaWikiServices $coreServices
	 * @codeCoverageIgnore
	 * @return static
	 */
	public static function wrap( MediaWikiServices $coreServices ) {
		return new static( $coreServices );
	}

	// Service aliases
	// phpcs:disable MediaWiki.Commenting.FunctionComment

	/**
	 * @codeCoverageIgnore
	 */
	public function getAutoModeratorConfig(): Config {
		return $this->coreServices->get( 'AutoModeratorConfig' );
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function getAutoModeratorWikiConfig(): Config {
		return $this->coreServices->get( 'AutoModeratorWikiConfigLoader' );
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function getWikiPageConfig(): WikiPageConfig {
		return $this->coreServices->get( 'AutoModeratorWikiPageConfig' );
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function getWikiPageConfigLoader(): WikiPageConfigLoader {
		return $this->coreServices->get( 'AutoModeratorWikiPageConfigLoader' );
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function getWikiPageConfigValidatorFactory(): ConfigValidatorFactory {
		return $this->coreServices->get( 'AutoModeratorConfigValidatorFactory' );
	}
}
