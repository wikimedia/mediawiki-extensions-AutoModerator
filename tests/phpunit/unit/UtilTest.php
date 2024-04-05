<?php

namespace AutoModerator\Tests;

use AutoModerator\Util;
use MediaWiki\Config\Config;
use MediaWikiUnitTestCase;

#[\AllowDynamicProperties]
/**
 * @group AutoModerator
 * @group extensions
 * @coversDefaultClass \AutoModerator\Util
 */
class UtilTest extends MediaWikiUnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock( Config::class );
	}

	protected function tearDown(): void {
		unset(
			$this->config
		);
		parent::tearDown();
	}

	/**
	 * @covers ::getWikiID
	 */
	public function testGetWikiIDFromConfig() {
		$this->config->method( 'get' )->willReturnMap( [
				[ 'AutoModeratorWikiId', 'testwiki' ],
		] );
		$wikiId = Util::getWikiID(
			$this->config
		);
		$this->assertSame(
			'testwiki',
			$wikiId
		);
	}
}
