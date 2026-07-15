<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\AutoModerator;

use MediaWiki\Config\Config;
use MediaWiki\MediaWikiServices;

/**
 * A simple wrapper for MediaWikiServices, to support type safety when accessing
 * services defined by this extension.
 */
readonly class AutoModeratorServices {

	public function __construct( private MediaWikiServices $coreServices ) {
	}

	/**
	 * Static version of the constructor, for nicer syntax.
	 * @codeCoverageIgnore
	 */
	public static function wrap( MediaWikiServices $coreServices ): static {
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
