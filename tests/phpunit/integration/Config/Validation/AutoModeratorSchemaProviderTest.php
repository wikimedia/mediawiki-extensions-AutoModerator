<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\AutoModerator\Tests\Integration;

use MediaWiki\Extension\CommunityConfiguration\Tests\SchemaProviderTestCase;
use MediaWiki\Registration\ExtensionRegistry;

if ( !ExtensionRegistry::getInstance()->isLoaded( 'CommunityConfiguration' ) ) {
	// Skip all tests in this class if CommunityConfiguration is not loaded.
	return;
}

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

		$this->overrideConfigValue( 'AutoModeratorMultiLingualRevertRisk', null );
	}
}
