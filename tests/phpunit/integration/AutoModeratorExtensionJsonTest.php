<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\AutoModerator\Tests\Integration;

use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Tests\ExtensionJsonTestBase;

/**
 * @group Test
 * @group AutoModerator
 * @coversNothing
 */
class AutoModeratorExtensionJsonTest extends ExtensionJsonTestBase {

	/** @inheritDoc */
	protected static string $extensionJsonPath = __DIR__ . '/../../../extension.json';

	public static function provideHookHandlerNames(): iterable {
		$extRegistry = ExtensionRegistry::getInstance();
		foreach ( self::getExtensionJson()['HookHandlers'] ?? [] as $name => $specification ) {
			if ( ( $name === 'ores' && !$extRegistry->isLoaded( 'ORES' ) ) ||
				( $name === 'communityconfiguration' && !$extRegistry->isLoaded( 'CommunityConfiguration' ) )
			) {
				continue;
			}
			yield [ $name ];
		}
	}
}
