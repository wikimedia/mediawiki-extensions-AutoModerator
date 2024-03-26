<?php

namespace AutoModerator\Config;

use AutoModerator\AutoModeratorServices;
use MediaWiki\MediaWikiServices;

trait AutoModeratorConfigLoaderStaticTrait {
	/**
	 * simple service wrapper
	 *
	 * @return \AutoModerator\Config\WikiPageConfig
	 */
	private static function getAutoModeratorWikiConfig() {
		return AutoModeratorServices::wrap(
			MediaWikiServices::getInstance()
		)->getAutoModeratorWikiConfig();
	}

	/**
	 * simple service wrapper
	 *
	 * @return \MediaWiki\Config\Config
	 */
	private static function getAutoModeratorConfig() {
		return AutoModeratorServices::wrap(
			MediaWikiServices::getInstance()
		)->getAutoModeratorConfig();
	}
}
