<?php

namespace AutoModerator\Config;

use AutoModerator\AutoModeratorServices;
use MediaWiki\Config\Config;
use MediaWiki\MediaWikiServices;

trait AutoModeratorConfigLoaderStaticTrait {
	/**
	 * simple service wrapper
	 *
	 * @return WikiPageConfig
	 */
	private static function getAutoModeratorWikiConfig() {
		return AutoModeratorServices::wrap(
			MediaWikiServices::getInstance()
		)->getAutoModeratorWikiConfig();
	}

	/**
	 * simple service wrapper
	 *
	 * @return Config
	 */
	private static function getAutoModeratorConfig() {
		return AutoModeratorServices::wrap(
			MediaWikiServices::getInstance()
		)->getAutoModeratorConfig();
	}
}
