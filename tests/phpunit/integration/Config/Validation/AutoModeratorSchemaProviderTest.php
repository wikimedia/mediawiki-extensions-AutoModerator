<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\CommunityConfigurationExample\Tests\Integration;

use MediaWiki\Extension\CommunityConfiguration\Tests\SchemaProviderTestCase;

/**
 * @coversNothing
 */
class AutoModeratorSchemaProviderTest extends SchemaProviderTestCase {

	protected function getExtensionName(): string {
		return 'AutoModerator';
	}

	protected function getProviderId(): string {
		return 'AutoModerator';
	}

	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValue( 'AutoModeratorEnableWikiConfig', true );
		$this->overrideConfigValue( 'AutoModeratorMultiLingualRevertRisk', null );
	}
}
