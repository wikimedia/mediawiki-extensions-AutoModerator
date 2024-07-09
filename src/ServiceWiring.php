<?php

use AutoModerator\AutoModeratorServices;
use AutoModerator\Config\AutoModeratorWikiConfigLoader;
use AutoModerator\Config\Validation\ConfigValidatorFactory;
use AutoModerator\Config\WikiPageConfig;
use AutoModerator\Config\WikiPageConfigLoader;
use MediaWiki\Config\Config;
use MediaWiki\Config\GlobalVarConfig;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

/**
 * @codeCoverageIgnore
 */
return [

	'AutoModeratorConfig' => static function ( MediaWikiServices $services ): Config {
		return $services->getConfigFactory()->makeConfig( 'AutoModerator' );
	},

	'AutoModeratorWikiConfigLoader' => static function ( MediaWikiServices $services ): Config {
		$autoModeratorServices = AutoModeratorServices::wrap( $services );
		return new AutoModeratorWikiConfigLoader(
			$autoModeratorServices->getWikiPageConfig(),
			GlobalVarConfig::newInstance()
		);
	},

	'AutoModeratorConfigValidatorFactory' => static function (
		MediaWikiServices $services
	): ConfigValidatorFactory {
		$autoModeratorServices = AutoModeratorServices::wrap( $services );
		return new ConfigValidatorFactory(
			$services->getTitleFactory()
		);
	},

	'AutoModeratorWikiPageConfig' => static function ( MediaWikiServices $services ): Config {
		$autoModeratorServices = AutoModeratorServices::wrap( $services );
		return new WikiPageConfig(
			LoggerFactory::getInstance( 'AutoModerator' ),
			$services->getTitleFactory(),
			$autoModeratorServices->getWikiPageConfigLoader(),
			defined( 'MW_PHPUNIT_TEST' ) && $services->isStorageDisabled()
		);
	},

	'AutoModeratorWikiPageConfigLoader' => static function (
		MediaWikiServices $services
	): WikiPageConfigLoader {
		return new WikiPageConfigLoader(
			$services->getMainWANObjectCache(),
			AutoModeratorServices::wrap( $services )
				->getWikiPageConfigValidatorFactory(),
			$services->getHttpRequestFactory(),
			$services->getRevisionLookup(),
			$services->getTitleFactory(),
			$services->getUrlUtils(),
			defined( 'MW_PHPUNIT_TEST' ) && $services->isStorageDisabled()
		);
	}
];
