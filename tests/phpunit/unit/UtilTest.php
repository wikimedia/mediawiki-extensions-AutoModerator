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
	 * @covers ::getRevertThreshold cautious
	 */
	public function testGetRevertThresholdCautiousLanguageAgnostic() {
		$wikiConfig = $this->createMock( Config::class );
		$wikiConfig->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorCautionLevel', 'cautious' ],
		] );
		$wikiConfig->method( 'has' )->willReturn( true );
		$config = $this->createMock( Config::class );
		$config->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorUsername', 'AutoModerator' ],
			[ 'AutoModeratorWikiId', 'enwiki' ],
			[ 'AutoModeratorMultiLingualRevertRisk', false ],
		] );
		$revertThreshold = Util::getRevertThreshold( $config, $wikiConfig );
		$this->assertSame(
			0.985,
			$revertThreshold
		);
	}

	/**
	 * @covers ::getRevertThreshold very-cautious
	 */
	public function testGetRevertThresholdVeryCautiousLanguageAgnostic() {
		$wikiConfig = $this->createMock( Config::class );
		$wikiConfig->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorCautionLevel', 'very-cautious' ],
			[ 'AutoModeratorMultilingualConfigCautionLevel', 'very-cautious' ]
		] );
		$wikiConfig->method( 'has' )->willReturn( true );
		$config = $this->createMock( Config::class );
		$config->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorUsername', 'AutoModerator' ],
			[ 'AutoModeratorWikiId', 'enwiki' ],
			[ 'AutoModeratorMultiLingualRevertRisk', true ],
		] );
		$revertThreshold = Util::getRevertThreshold( $config, $wikiConfig );
		$this->assertSame(
			0.990,
			$revertThreshold
		);
	}

	/**
	 * @covers ::getRevertThreshold somewhat-cautious
	 */
	public function testGetRevertThresholdSomewhatCautious() {
		$wikiConfig = $this->createMock( Config::class );
		$wikiConfig->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorCautionLevel', 'somewhat-cautious' ],
		] );
		$wikiConfig->method( 'has' )->willReturn( true );
		$config = $this->createMock( Config::class );
		$config->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorUsername', 'AutoModerator' ],
			[ 'AutoModeratorWikiId', 'enwiki' ],
			[ 'AutoModeratorMultiLingualRevertRisk', false ],
		] );
		$revertThreshold = Util::getRevertThreshold( $config, $wikiConfig );
		$this->assertSame(
			0.980,
			$revertThreshold
		);
	}

	/**
	 * @covers ::getRevertThreshold less-cautious
	 */
	public function testGetRevertThresholdLessCautiousLanguageAgnostic() {
		$wikiConfig = $this->createMock( Config::class );
		$wikiConfig->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorCautionLevel', 'less-cautious' ],
		] );
		$wikiConfig->method( 'has' )->willReturn( true );
		$config = $this->createMock( Config::class );
		$config->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorUsername', 'AutoModerator' ],
			[ 'AutoModeratorWikiId', 'enwiki' ],
			[ 'AutoModeratorMultiLingualRevertRisk', false ],
		] );
		$revertThreshold = Util::getRevertThreshold( $config, $wikiConfig );
		$this->assertSame(
			0.975,
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
			[ 'AutoModeratorWikiId', 'enwiki' ],
			[ 'AutoModeratorMultiLingualRevertRisk', true ],
		] );

		$wikiConfig = $this->createMock( Config::class );
		$wikiConfig->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorMultilingualConfigEnableMultilingual', false ],
			[ 'AutoModeratorMultilingualConfigMultilingualThreshold', null ]
		] );

		$client = Util::initializeLiftWingClient( $config, $wikiConfig );

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
			[ 'AutoModeratorWikiId', 'enwiki' ],
			[ 'AutoModeratorMultiLingualRevertRisk', true ],
		] );

		$wikiConfig = $this->createMock( Config::class );
		$wikiConfig->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorMultilingualConfigEnableMultilingual', false ],
			[ 'AutoModeratorMultilingualConfigMultilingualThreshold', null ]
		] );

		$client = Util::initializeLiftWingClient( $config, $wikiConfig );

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
	 * When there multilingual model for that wiki is enabled
	 */
	public function testInitializeLiftWingClientMultiLingualModel() {
		$expectedUrl = 'example.org';
		$model = 'revertrisk-multilingual';
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
			[ 'AutoModeratorWikiId', 'enwiki' ],
			[ 'AutoModeratorMultiLingualRevertRisk', true ],
		] );

		$wikiConfig = $this->createMock( Config::class );
		$wikiConfig->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorMultilingualConfigEnableMultilingual', true ],
			[ 'AutoModeratorMultilingualConfigMultilingualThreshold', 0.987 ]
		] );

		$client = Util::initializeLiftWingClient( $config, $wikiConfig );

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

	/**
	 * @covers ::isMultiLingualRevertRiskEnabled
	 */
	public function testIsMultiLingualRevertRiskEnabledFalse() {
		$wikiConfig = $this->createMock( Config::class );
		$wikiConfig->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorMultilingualConfigEnableMultilingual', false ],
			[ 'AutoModeratorMultilingualConfigMultilingualThreshold', null ]
		] );
		$config = $this->createMock( Config::class );
		$config->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorMultiLingualRevertRisk', true ],
		] );

		$isMultiLingualRevertRiskEnabled = Util::isMultiLingualRevertRiskEnabled( $config, $wikiConfig );

		$this->assertFalse( $isMultiLingualRevertRiskEnabled );
	}

	/**
	 * @covers ::isWikiMultilingual
	 */
	public function testIsWikiMultilingual() {
		$config = $this->createMock( Config::class );
		$config->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorWikiId', 'testwiki' ],
			[ 'AutoModeratorMultiLingualRevertRisk', true ],
		] );

		$isWikiMultilingual = Util::isWikiMultilingual( $config );

		$this->assertTrue( $isWikiMultilingual );
	}

	/**
	 * @covers ::isWikiMultilingual
	 */
	public function testIsWikiMultilingualNot() {
		$config = $this->createMock( Config::class );
		$config->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorWikiId', 'testwiki' ],
			[ 'AutoModeratorMultiLingualRevertRisk', false ],
		] );

		$isWikiMultilingual = Util::isWikiMultilingual( $config );

		$this->assertFalse( $isWikiMultilingual );
	}

	/**
	 * @covers ::isMultiLingualRevertRiskEnabled
	 */
	public function testIsMultiLingualRevertRiskEnabledTrue() {
		$wikiConfig = $this->createMock( Config::class );
		$wikiConfig->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorMultilingualConfigEnableMultilingual', true ],
			[ 'AutoModeratorMultilingualConfigMultilingualThreshold', 0.987 ]
		] );
		$config = $this->createMock( Config::class );
		$config->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorWikiId', 'enwiki' ],
			[ 'AutoModeratorMultiLingualRevertRisk', true ],
		] );

		$isMultiLingualRevertRiskEnabled = Util::isMultiLingualRevertRiskEnabled( $config, $wikiConfig );

		$this->assertTrue( $isMultiLingualRevertRiskEnabled );
	}

	/**
	 * @covers ::getRevertRiskModel
	 * @covers ::isMultiLingualRevertRiskEnabled
	 * @covers ::getORESLanguageAgnosticModelName
	 * @covers ::getORESMultiLingualModelName
	 */
	public function testGetRevertRiskModelMultiLingual() {
		$wikiId = "enwiki";
		$config = $this->createMock( Config::class );
		$config->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorWikiId', $wikiId ],
			[ 'AutoModeratorMultiLingualRevertRisk', true ],
		] );
		$wikiConfig = $this->createMock( Config::class );
		$wikiConfig->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorMultilingualConfigEnableMultilingual', true ],
			[ 'AutoModeratorMultilingualConfigMultilingualThreshold', 0.987 ]
		] );

		$expectedRevertRiskModelName = Util::getORESMultiLingualModelName();
		$revertRiskModelName = Util::getRevertRiskModel( $config, $wikiConfig );

		$this->assertSame( $expectedRevertRiskModelName, $revertRiskModelName );
	}

	/**
	 * @covers ::getRevertRiskModel
	 * @covers ::isMultiLingualRevertRiskEnabled
	 * @covers ::getORESLanguageAgnosticModelName
	 * @covers ::getORESMultiLingualModelName
	 */
	public function testGetRevertRiskModelLanguageAgnostic() {
		$wikiId = "enwiki";
		$config = $this->createMock( Config::class );
		$config->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorWikiId', $wikiId ],
			[ 'AutoModeratorMultiLingualRevertRisk', true ],
		] );
		$wikiConfig = $this->createMock( Config::class );
		$wikiConfig->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorMultilingualConfigEnableMultilingual', false ],
			[ 'AutoModeratorMultilingualConfigMultilingualThreshold', null ]
		] );

		$expectedRevertRiskModelName = Util::getORESLanguageAgnosticModelName();
		$revertRiskModelName = Util::getRevertRiskModel( $config, $wikiConfig );

		$this->assertSame( $expectedRevertRiskModelName, $revertRiskModelName );
	}

	/**
	 * @covers ::getMultiLingualThreshold
	 */
	public function testGetMultiLingualThreshold() {
		$wikiConfig = $this->createMock( Config::class );
		$wikiConfig->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorMultilingualConfigEnableMultilingual', true ],
			[ 'AutoModeratorMultilingualConfigMultilingualThreshold', 0.987 ]
		] );

		$expectedThreshold = 0.987;
		$threshold = Util::getMultiLingualThreshold( $wikiConfig );

		$this->assertSame( $expectedThreshold, $threshold );
	}
}
