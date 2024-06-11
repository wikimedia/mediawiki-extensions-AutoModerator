<?php

namespace AutoModerator\Tests;

use AutoModerator\LiftWingClient;
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

	/**
	 * @covers ::initializeLiftWingClient
	 * when AutoModeratorLiftWingAddHostHeader false
	 */
	public function testInitializeLiftWingClientWithoutHostHeader() {
		$expectedUrl = 'example.org';
		$expectedModel = 'revertrisk-language-agnostic';
		$expectedLang = 'en';
		$expectedClient = new LiftWingClient(
			$expectedModel,
			$expectedLang,
			$expectedUrl
		);

		$this->config->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorLiftWingBaseUrl', $expectedUrl ],
			[ 'AutoModeratorLiftWingAddHostHeader', false ],
		] );

		$client = Util::initializeLiftWingClient( $this->config );

		$this->assertSame(
			$expectedClient->getBaseUrl(),
			$client->getBaseUrl()
		);

		$this->assertSame(
			$expectedClient->getHostHeader(),
			$client->getHostHeader()
		);
	}

	/**
	 * @covers ::initializeLiftWingClient
	 * when AutoModeratorLiftWingAddHostHeader true
	 */
	public function testInitializeLiftWingClientWithHostHeader() {
		$expectedUrl = 'example.org';
		$model = 'revertrisk-language-agnostic';
		$lang = 'en';
		$expectedHostHeader = "host-header";
		$expectedClient = new LiftWingClient(
			$model,
			$lang,
			$expectedUrl,
			$expectedHostHeader
		);

		$this->config->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorLiftWingBaseUrl', $expectedUrl ],
			[ 'AutoModeratorLiftWingAddHostHeader', true ],
			[ 'AutoModeratorLiftWingRevertRiskHostHeader', $expectedHostHeader ],
		] );

		$client = Util::initializeLiftWingClient( $this->config );

		$this->assertSame(
			$expectedClient->getBaseUrl(),
			$client->getBaseUrl()
		);

		$this->assertSame(
			$expectedClient->getHostHeader(),
			$client->getHostHeader()
		);
	}

	/**
	 * @covers ::getLanguageConfiguration
	 */
	public function testGetLanguageConfiguration() {
		$wikiId = "idwiki";
		$expectedLang = "id";

		$this->config->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorWikiId', $wikiId ],
		] );

		$actual = Util::getLanguageConfiguration( $this->config );
		$this->assertSame(
			$expectedLang,
			$actual
		);
	}
}
