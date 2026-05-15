<?php

namespace AutoModerator\Tests\Integration;

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
		foreach ( self::getExtensionJson()['HookHandlers'] ?? [] as $name => $specification ) {
			if ( $name === 'ores' && !ExtensionRegistry::getInstance()->isLoaded( 'ORES' ) ) {
				continue;
			}
			yield [ $name ];
		}
	}
}
