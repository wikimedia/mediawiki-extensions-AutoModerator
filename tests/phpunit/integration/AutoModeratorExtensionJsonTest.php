<?php

namespace AutoModerator\Tests\Integration;

use MediaWiki\Tests\ExtensionJsonTestBase;

/**
 * @group Test
 * @group AutoModerator
 * @coversNothing
 */
class AutoModeratorExtensionJsonTest extends ExtensionJsonTestBase {

	/** @inheritDoc */
	protected static string $extensionJsonPath = __DIR__ . '/../../../extension.json';

}
