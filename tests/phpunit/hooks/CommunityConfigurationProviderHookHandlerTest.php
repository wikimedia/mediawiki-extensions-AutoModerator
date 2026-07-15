<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\AutoModerator\Tests\Hooks;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\AutoModerator\Hooks\CommunityConfigurationProviderHookHandler;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\AutoModerator\Hooks\CommunityConfigurationProviderHookHandler
 */
class CommunityConfigurationProviderHookHandlerTest extends MediaWikiIntegrationTestCase {

	public function testOnCommunityConfigurationProviderAutoModConfig() {
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

		( new CommunityConfigurationProviderHookHandler( $config ) )
			->onCommunityConfigurationProvider_initList( $providers );

		$this->assertArrayHasKey( 'AutoModerator', $providers );
		$this->assertArrayNotHasKey( 'AutomoderatorMultilingual', $providers );
	}

	public function testOnCommunityConfigurationProviderMultilingualConfig() {
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

		( new CommunityConfigurationProviderHookHandler( $config ) )
			->onCommunityConfigurationProvider_initList( $providers );

		$this->assertArrayHasKey( 'AutomoderatorMultilingual', $providers );
		$this->assertArrayNotHasKey( 'AutoModerator', $providers );
	}
}
