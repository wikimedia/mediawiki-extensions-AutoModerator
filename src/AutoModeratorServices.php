<?php

namespace AutoModerator;

use MediaWiki\Config\Config;
use MediaWiki\MediaWikiServices;

/**
 * A simple wrapper for MediaWikiServices, to support type safety when accessing
 * services defined by this extension.
 */
class AutoModeratorServices {

	public function __construct( private readonly MediaWikiServices $coreServices ) {
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

	/**
	 * @codeCoverageIgnore
	 */
	public function getAutoModeratorConfig(): Config {
		return $this->coreServices->get( 'AutoModeratorConfig' );
	}
}
