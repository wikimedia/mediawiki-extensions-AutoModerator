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

	/**
	 * @covers ::getRevertThreshold defaults to 0.95 when set below 0.95.
	 */
	public function testGetRevertThreshold() {
		$this->config->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorRevertProbability', '0' ],
		] );
		$revertThreshold = Util::getRevertThreshold(
			$this->config
		);
		$this->assertSame(
			0.95,
			$revertThreshold
		);
	}

	/**
	 * @covers ::getRevertThreshold when configured above 0.95
	 *  respects the configuration value.
	 */
	public function testGetRevertThresholdNotTooLow() {
		$this->config->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorRevertProbability', '0.97' ],
		] );
		$revertThreshold = Util::getRevertThreshold( $this->config );
		$this->assertSame(
			0.97,
			$revertThreshold
		);
	}
}
