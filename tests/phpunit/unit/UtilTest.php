<?php

namespace AutoModerator\Tests;

use AutoModerator\LiftWingClient;
use AutoModerator\Util;
use MediaWiki\Config\Config;
use MediaWikiUnitTestCase;

/**
 * @group AutoModerator
 * @group extensions
 * @coversDefaultClass \AutoModerator\Util
 */
class UtilTest extends MediaWikiUnitTestCase {

	/**
	 * @covers ::getWikiID
	 */
	public function testGetWikiIDFromConfig() {
		$config = $this->createMock( Config::class );
		$config->method( 'get' )->willReturnMap( [
				[ 'AutoModeratorWikiId', 'testwiki' ],
		] );
		$wikiId = Util::getWikiID(
			$config
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
		$config = $this->createMock( Config::class );
		$config->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorRevertProbability', '0' ],
		] );
		$revertThreshold = Util::getRevertThreshold( $config );
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
		$config = $this->createMock( Config::class );
		$config->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorRevertProbability', '0.97' ],
		] );
		$revertThreshold = Util::getRevertThreshold( $config );
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

		$config = $this->createMock( Config::class );
		$config->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorLiftWingBaseUrl', $expectedUrl ],
			[ 'AutoModeratorLiftWingAddHostHeader', false ],
		] );

		$client = Util::initializeLiftWingClient( $config );

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

		$config = $this->createMock( Config::class );
		$config->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorLiftWingBaseUrl', $expectedUrl ],
			[ 'AutoModeratorLiftWingAddHostHeader', true ],
			[ 'AutoModeratorLiftWingRevertRiskHostHeader', $expectedHostHeader ],
		] );

		$client = Util::initializeLiftWingClient( $config );

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

		$config = $this->createMock( Config::class );
		$config->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorWikiId', $wikiId ],
		] );

		$actual = Util::getLanguageConfiguration( $config );
		$this->assertSame(
			$expectedLang,
			$actual
		);
	}
}
