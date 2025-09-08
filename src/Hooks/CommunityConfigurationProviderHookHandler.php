<?php

namespace AutoModerator\Hooks;

use AutoModerator\Config\Validation\AutoModeratorMultilingualConfigSchema;
use AutoModerator\Config\WikiPageConfigLoader;
use AutoModerator\Util;
use MediaWiki\Config\Config;
use MediaWiki\Extension\CommunityConfiguration\Hooks\CommunityConfigurationProvider_initListHook;
use MediaWiki\Title\TitleFactory;

class CommunityConfigurationProviderHookHandler implements CommunityConfigurationProvider_initListHook {

	private Config $config;
	private WikiPageConfigLoader $configLoader;
	private TitleFactory $titleFactory;

	/**
	 * @param Config $config
	 * @param WikiPageConfigLoader $configLoader
	 * @param TitleFactory $titleFactory
	 */
	public function __construct( Config $config, WikiPageConfigLoader $configLoader, TitleFactory $titleFactory ) {
		$this->config = $config;
		$this->configLoader = $configLoader;
		$this->titleFactory = $titleFactory;
	}

	/**
	 * @inheritDoc
	 */
	public function onCommunityConfigurationProvider_initList( array &$providers ) {
		$multiLingualWikis = $this->config->get( 'AutoModeratorMultiLingualRevertRisk' );
		if ( !$multiLingualWikis ) {
			unset( $providers['MultilingualConfig'] );
			return;
		}
		$wikiId = Util::getWikiID( $this->config );
		if ( in_array( $wikiId, $multiLingualWikis ) ) {
			// The multilingual model can be configured in this wiki, adding the new configuration
			// and unsetting the original CC form
			$providers['MultilingualConfig'] = [
				"store" => [
					"type" => "wikipage",
					"args" => [
						"MediaWiki:AutoModeratorMultilingualConfig.json",
					],
				],
				"validator" => [
					"type" => "jsonschema",
					"args" => [
						AutoModeratorMultilingualConfigSchema::class,
					],
				],
				"type" => "mw-config",
			];
			unset( $providers['AutoModerator'] );
			$configTitle = $this->titleFactory->makeTitleSafe(
				NS_MEDIAWIKI,
				'AutoModeratorMultilingualConfig.json'
			);
			if ( $configTitle === null ) {
				return;
			}
			$this->configLoader->load( $configTitle );
		} else {
			unset( $providers['MultilingualConfig'] );
		}
	}

}
