<?php

namespace AutoModerator\Tests\Hooks;

use AutoModerator\AutoModeratorServices;
use AutoModerator\Config\Validation\AutoModeratorMultilingualConfigSchema;
use AutoModerator\Hooks\CommunityConfigurationProviderHookHandler;
use MediaWiki\Config\HashConfig;
use MediaWikiIntegrationTestCase;

class CommunityConfigurationProviderHookHandlerTest extends MediaWikiIntegrationTestCase {

	/**
	 * @covers \AutoModerator\Hooks\CommunityConfigurationProviderHookHandler
	 */
	public function testOnCommunityConfigurationProviderAutoModConfig() {
		$services = $this->getServiceContainer();
		$titleFactory = $services->getTitleFactory();
		$autoModeratorServices = AutoModeratorServices::wrap( $services );
		$wikiPageConfigLoader = $autoModeratorServices->getWikiPageConfigLoader();
		$config = new HashConfig( [
			'AutoModeratorEnableWikiConfig' => true,
			'AutoModeratorEnableRevisionCheck' => true,
			'AutoModeratorUsername' => 'AutoModerator',
			'AutoModeratorWikiId' => 'enwiki',
			'AutoModeratorFalsePositivePageTitle' => 'Test False Positive',
			'AutoModeratorMultiLingualRevertRisk' => false
		] );

		$providers = [
			"AutoModerator" => [
				"store" => [
					"type" => "wikipage",
					"args" => [
						"MediaWiki:AutoModeratorConfig.json",
					],
				],
				"validator" => [
					"type" => "jsonschema",
					"args" => [
						AutoModeratorMultilingualConfigSchema::class,
					],
				],
				"type" => "mw-config"
			],
			"AutomoderatorMultilingual" => [
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
				"type" => "mw-config"
			]
		];

		$this->assertArrayHasKey( 'AutoModerator', $providers );
		$this->assertArrayHasKey( 'AutomoderatorMultilingual', $providers );

		( new CommunityConfigurationProviderHookHandler(
			$config,
			$wikiPageConfigLoader,
			$titleFactory
		) )->onCommunityConfigurationProvider_initList( $providers );

		$this->assertArrayHasKey( 'AutoModerator', $providers );
		$this->assertArrayNotHasKey( 'AutomoderatorMultilingual', $providers );
	}

	/**
	 * @covers \AutoModerator\Hooks\CommunityConfigurationProviderHookHandler
	 */
	public function testOnCommunityConfigurationProviderMultilingualConfig() {
		$services = $this->getServiceContainer();
		$titleFactory = $services->getTitleFactory();
		$autoModeratorServices = AutoModeratorServices::wrap( $services );
		$wikiPageConfigLoader = $autoModeratorServices->getWikiPageConfigLoader();
		$config = new HashConfig( [
			'AutoModeratorEnableWikiConfig' => true,
			'AutoModeratorEnableRevisionCheck' => true,
			'AutoModeratorUsername' => 'AutoModerator',
			'AutoModeratorWikiId' => 'enwiki',
			'AutoModeratorFalsePositivePageTitle' => 'Test False Positive',
			'AutoModeratorMultiLingualRevertRisk' => true
		] );

		$providers = [
			"AutoModerator" => [
				"store" => [
					"type" => "wikipage",
					"args" => [
						"MediaWiki:AutoModeratorConfig.json",
					],
				],
				"validator" => [
					"type" => "jsonschema",
					"args" => [
						AutoModeratorMultilingualConfigSchema::class,
					],
				],
				"type" => "mw-config"
			],
			"AutomoderatorMultilingual" => [
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
				"type" => "mw-config"
			]
		];

		$this->assertArrayHasKey( 'AutoModerator', $providers );
		$this->assertArrayHasKey( 'AutomoderatorMultilingual', $providers );

		( new CommunityConfigurationProviderHookHandler(
			$config,
			$wikiPageConfigLoader,
			$titleFactory
		) )->onCommunityConfigurationProvider_initList( $providers );

		$this->assertArrayHasKey( 'AutomoderatorMultilingual', $providers );
		$this->assertArrayNotHasKey( 'AutoModerator', $providers );
	}
}
