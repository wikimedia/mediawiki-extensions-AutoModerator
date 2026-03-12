<?php

namespace AutoModerator\Tests\Hooks;

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
		$config = new HashConfig( [
			'AutoModeratorEnableRevisionCheck' => true,
			'AutoModeratorUsername' => 'AutoModerator',
			'AutoModeratorWikiId' => 'enwiki',
			'AutoModeratorFalsePositivePageTitle' => 'Test False Positive',
			'AutoModeratorMultiLingualRevertRisk' => false
		] );

		$providers = [
			'AutoModerator' => [],
			'AutomoderatorMultilingual' => []
		];

		$this->assertArrayHasKey( 'AutoModerator', $providers );
		$this->assertArrayHasKey( 'AutomoderatorMultilingual', $providers );
		$this->overrideConfigValue( 'AutoModeratorMultiLingualRevertRisk', false );

		( new CommunityConfigurationProviderHookHandler(
			$config,
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
		$config = new HashConfig( [
			'AutoModeratorMultilingualConfigEnableRevisionCheck' => true,
			'AutoModeratorMultilingualConfigFalsePositivePageTitle' => 'Test False Positive',
			'AutoModeratorUsername' => 'AutoModerator',
			'AutoModeratorWikiId' => 'enwiki',
			'AutoModeratorMultiLingualRevertRisk' => true,
		] );

		$providers = [
			"AutoModerator" => [],
			"AutomoderatorMultilingual" => []
		];

		$this->assertArrayHasKey( 'AutoModerator', $providers );
		$this->assertArrayHasKey( 'AutomoderatorMultilingual', $providers );
		$this->overrideConfigValue( 'AutoModeratorMultiLingualRevertRisk', true );

		( new CommunityConfigurationProviderHookHandler(
			$config,
			$titleFactory
		) )->onCommunityConfigurationProvider_initList( $providers );

		$this->assertArrayHasKey( 'AutomoderatorMultilingual', $providers );
		$this->assertArrayNotHasKey( 'AutoModerator', $providers );
	}
}
