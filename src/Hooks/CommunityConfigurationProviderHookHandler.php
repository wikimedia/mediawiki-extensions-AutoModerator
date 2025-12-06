<?php

namespace AutoModerator\Hooks;

use AutoModerator\Config\Validation\AutoModeratorMultilingualConfigSchema;
use AutoModerator\Config\WikiPageConfigLoader;
use MediaWiki\Config\Config;
use MediaWiki\Extension\CommunityConfiguration\Hooks\CommunityConfigurationProvider_initListHook;
use MediaWiki\Title\TitleFactory;

class CommunityConfigurationProviderHookHandler implements CommunityConfigurationProvider_initListHook {

	public function __construct(
		private readonly Config $config,
		private readonly WikiPageConfigLoader $configLoader,
		private readonly TitleFactory $titleFactory,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function onCommunityConfigurationProvider_initList( array &$providers ) {
		$multiLingualWikis = $this->config->get( 'AutoModeratorMultiLingualRevertRisk' );
		if ( !$multiLingualWikis ) {
			unset( $providers['AutomoderatorMultilingual'] );
			return;
		}
		if ( $this->config->get( 'AutoModeratorMultiLingualRevertRisk' ) ) {
			// The multilingual model can be configured in this wiki, adding the new configuration
			// and unsetting the original CC form
			$providers['AutomoderatorMultilingual'] = [
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
			unset( $providers['AutomoderatorMultilingual'] );
		}
	}

}
