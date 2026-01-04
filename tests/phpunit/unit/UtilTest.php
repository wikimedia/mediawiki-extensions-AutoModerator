<?php

namespace AutoModerator\Tests;

use AutoModerator\LiftWingClient;
use AutoModerator\Util;
use MediaWiki\Config\HashConfig;
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
		$config = new HashConfig( [
			'AutoModeratorWikiId' => 'testwiki',
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
		$wikiConfig = new HashConfig( [
			'AutoModeratorCautionLevel' => 'cautious',
		] );
		$config = new HashConfig( [
			'AutoModeratorUsername' => 'AutoModerator',
			'AutoModeratorWikiId' => 'enwiki',
			'AutoModeratorMultiLingualRevertRisk' => false,
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
		$wikiConfig = new HashConfig( [
			'AutoModeratorCautionLevel' => 'very-cautious',
			'AutoModeratorMultilingualConfigCautionLevel' => 'very-cautious',
			'AutoModeratorMultilingualConfigEnableLanguageAgnostic' => false,
			'AutoModeratorMultilingualConfigMultilingualThreshold' => null,
		] );
		$config = new HashConfig( [
			'AutoModeratorUsername' => 'AutoModerator',
			'AutoModeratorWikiId' => 'enwiki',
			'AutoModeratorMultiLingualRevertRisk' => true,
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
		$wikiConfig = new HashConfig( [
			'AutoModeratorCautionLevel' => 'somewhat-cautious',
		] );
		$config = new HashConfig( [
			'AutoModeratorUsername' => 'AutoModerator',
			'AutoModeratorWikiId' => 'enwiki',
			'AutoModeratorMultiLingualRevertRisk' => false,
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
		$wikiConfig = new HashConfig( [
			'AutoModeratorCautionLevel' => 'less-cautious',
		] );
		$config = new HashConfig( [
			'AutoModeratorUsername' => 'AutoModerator',
			'AutoModeratorWikiId' => 'enwiki',
			'AutoModeratorMultiLingualRevertRisk' => false,
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

		$config = new HashConfig( [
			'AutoModeratorLiftWingBaseUrl' => $expectedUrl,
			'AutoModeratorLiftWingAddHostHeader' => false,
			'AutoModeratorWikiId' => 'enwiki',
			'AutoModeratorMultiLingualRevertRisk' => true,
		] );
		$wikiConfig = new HashConfig( [
			'AutoModeratorMultilingualConfigEnableMultilingual' => false,
			'AutoModeratorMultilingualConfigMultilingualThreshold' => null,
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

		$config = new HashConfig( [
			'AutoModeratorLiftWingBaseUrl' => $expectedUrl,
			'AutoModeratorLiftWingAddHostHeader' => true,
			'AutoModeratorLiftWingRevertRiskHostHeader' => $expectedHostHeader,
			'AutoModeratorWikiId' => 'enwiki',
			'AutoModeratorMultiLingualRevertRisk' => true,
		] );

		$wikiConfig = new HashConfig( [
			'AutoModeratorMultilingualConfigEnableMultilingual' => false,
			'AutoModeratorMultilingualConfigMultilingualThreshold' => null,
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

		$config = new HashConfig( [
			'AutoModeratorLiftWingBaseUrl' => $expectedUrl,
			'AutoModeratorLiftWingAddHostHeader' => true,
			'AutoModeratorLiftWingRevertRiskHostHeader' => $expectedHostHeader,
			'AutoModeratorWikiId' => 'enwiki',
			'AutoModeratorMultiLingualRevertRisk' => true,
		] );

		$wikiConfig = new HashConfig( [
			'AutoModeratorMultilingualConfigEnableMultilingual' => true,
			'AutoModeratorMultilingualConfigMultilingualThreshold' => 0.987,
			'AutoModeratorMultilingualConfigEnableLanguageAgnostic' => false,
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

		$config = new HashConfig( [
			'AutoModeratorWikiId' => $wikiId,
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
		$wikiConfig = new HashConfig( [
			'AutoModeratorMultilingualConfigEnableMultilingual' => false,
			'AutoModeratorMultilingualConfigMultilingualThreshold' => null,
		] );
		$config = new HashConfig( [
			'AutoModeratorMultiLingualRevertRisk' => true,
		] );

		$isMultiLingualRevertRiskEnabled = Util::isMultiLingualRevertRiskEnabled( $config, $wikiConfig );

		$this->assertFalse( $isMultiLingualRevertRiskEnabled );
	}

	/**
	 * @covers ::isWikiMultilingual
	 */
	public function testIsWikiMultilingual() {
		$config = new HashConfig( [
			'AutoModeratorWikiId' => 'testwiki',
			'AutoModeratorMultiLingualRevertRisk' => true,
		] );

		$isWikiMultilingual = Util::isWikiMultilingual( $config );

		$this->assertTrue( $isWikiMultilingual );
	}

	/**
	 * @covers ::isWikiMultilingual
	 */
	public function testIsWikiMultilingualNot() {
		$config = new HashConfig( [
			'AutoModeratorWikiId' => 'testwiki',
			'AutoModeratorMultiLingualRevertRisk' => false,
		] );

		$isWikiMultilingual = Util::isWikiMultilingual( $config );

		$this->assertFalse( $isWikiMultilingual );
	}

	/**
	 * @covers ::isMultiLingualRevertRiskEnabled
	 */
	public function testIsMultiLingualRevertRiskEnabledTrue() {
		$wikiConfig = new HashConfig( [
			'AutoModeratorMultilingualConfigEnableMultilingual' => true,
			'AutoModeratorMultilingualConfigMultilingualThreshold' => 0.987,
			'AutoModeratorMultilingualConfigEnableLanguageAgnostic' => false,
		] );
		$config = new HashConfig( [
			'AutoModeratorWikiId' => 'enwiki',
			'AutoModeratorMultiLingualRevertRisk' => true,
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
		$config = new HashConfig( [
			'AutoModeratorWikiId' => $wikiId,
			'AutoModeratorMultiLingualRevertRisk' => true,
		] );
		$wikiConfig = new HashConfig( [
			'AutoModeratorMultilingualConfigEnableMultilingual' => true,
			'AutoModeratorMultilingualConfigMultilingualThreshold' => 0.987,
			'AutoModeratorMultilingualConfigEnableLanguageAgnostic' => false,
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
		$config = new HashConfig( [
			'AutoModeratorWikiId' => $wikiId,
			'AutoModeratorMultiLingualRevertRisk' => true,
		] );
		$wikiConfig = new HashConfig( [
			'AutoModeratorMultilingualConfigEnableMultilingual' => false,
			'AutoModeratorMultilingualConfigMultilingualThreshold' => null,
		] );

		$expectedRevertRiskModelName = Util::getORESLanguageAgnosticModelName();
		$revertRiskModelName = Util::getRevertRiskModel( $config, $wikiConfig );

		$this->assertSame( $expectedRevertRiskModelName, $revertRiskModelName );
	}

	/**
	 * @covers ::getMultiLingualThreshold
	 */
	public function testGetMultiLingualThreshold() {
		$wikiConfig = new HashConfig( [
			'AutoModeratorMultilingualConfigEnableMultilingual' => true,
			'AutoModeratorMultilingualConfigMultilingualThreshold' => 0.987,
		] );

		$expectedThreshold = 0.987;
		$threshold = Util::getMultiLingualThreshold( $wikiConfig );

		$this->assertSame( $expectedThreshold, $threshold );
	}
}
