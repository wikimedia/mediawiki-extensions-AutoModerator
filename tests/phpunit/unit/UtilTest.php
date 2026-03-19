<?php

namespace AutoModerator\Tests;

use AutoModerator\LiftWingClient;
use AutoModerator\Util;
use MediaWiki\Config\HashConfig;
use MediaWiki\Http\HttpRequestFactory;
use MediaWikiUnitTestCase;

/**
 * @group AutoModerator
 * @group extensions
 * @coversDefaultClass \AutoModerator\Util
 */
class UtilTest extends MediaWikiUnitTestCase {

	/** @var HttpRequestFactory */
	private $httpRequestFactory;

	public function setUp(): void {
		$this->httpRequestFactory = $this->createMock( HttpRequestFactory::class );
	}

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
		$config = new HashConfig( [
			'AutoModeratorUsername' => 'AutoModerator',
			'AutoModeratorWikiId' => 'enwiki',
			'AutoModeratorMultiLingualRevertRisk' => false,
			'AutoModeratorCautionLevel' => 'cautious',
		] );
		$revertThreshold = Util::getRevertThreshold( $config );
		$this->assertSame(
			0.985,
			$revertThreshold
		);
	}

	/**
	 * @covers ::getRevertThreshold very-cautious
	 */
	public function testGetRevertThresholdVeryCautiousLanguageAgnostic() {
		$config = new HashConfig( [
			'AutoModeratorUsername' => 'AutoModerator',
			'AutoModeratorWikiId' => 'enwiki',
			'AutoModeratorMultiLingualRevertRisk' => true,
			'AutoModeratorCautionLevel' => 'very-cautious',
			'AutoModeratorMultilingualConfigCautionLevel' => 'very-cautious',
			'AutoModeratorMultilingualConfigEnableLanguageAgnostic' => false,
			'AutoModeratorMultilingualConfigMultilingualThreshold' => null,
		] );
		$revertThreshold = Util::getRevertThreshold( $config );
		$this->assertSame(
			0.990,
			$revertThreshold
		);
	}

	/**
	 * @covers ::getRevertThreshold somewhat-cautious
	 */
	public function testGetRevertThresholdSomewhatCautious() {
		$config = new HashConfig( [
			'AutoModeratorUsername' => 'AutoModerator',
			'AutoModeratorWikiId' => 'enwiki',
			'AutoModeratorMultiLingualRevertRisk' => false,
			'AutoModeratorCautionLevel' => 'somewhat-cautious',
		] );
		$revertThreshold = Util::getRevertThreshold( $config );
		$this->assertSame(
			0.980,
			$revertThreshold
		);
	}

	/**
	 * @covers ::getRevertThreshold less-cautious
	 */
	public function testGetRevertThresholdLessCautiousLanguageAgnostic() {
		$config = new HashConfig( [
			'AutoModeratorUsername' => 'AutoModerator',
			'AutoModeratorWikiId' => 'enwiki',
			'AutoModeratorMultiLingualRevertRisk' => false,
			'AutoModeratorCautionLevel' => 'less-cautious',
		] );
		$revertThreshold = Util::getRevertThreshold( $config );
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
			$this->httpRequestFactory,
			$expectedModel,
			$expectedLang,
			$expectedUrl
		);

		$config = new HashConfig( [
			'AutoModeratorLiftWingBaseUrl' => $expectedUrl,
			'AutoModeratorLiftWingAddHostHeader' => false,
			'AutoModeratorWikiId' => 'enwiki',
			'AutoModeratorMultiLingualRevertRisk' => true,
			'AutoModeratorMultilingualConfigEnableMultilingual' => false,
			'AutoModeratorMultilingualConfigMultilingualThreshold' => null,
		] );

		$client = Util::initializeLiftWingClient( $this->httpRequestFactory, $config );

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
			$this->httpRequestFactory,
			$model,
			$lang,
			$expectedUrl,
			$expectedHostHeader
		);

		$config = new HashConfig( [
			'AutoModeratorLiftWingBaseUrl' => $expectedUrl,
			'AutoModeratorLiftWingAddHostHeader' => true,
			'AutoModeratorLiftWingRevertRiskHostHeader' => $expectedHostHeader,
			'AutoModeratorLiftWingMultiLingualRevertRiskHostHeader' => "another-host-header",
			'AutoModeratorWikiId' => 'enwiki',
			'AutoModeratorMultiLingualRevertRisk' => true,
			'AutoModeratorMultilingualConfigEnableMultilingual' => false,
			'AutoModeratorMultilingualConfigMultilingualThreshold' => null,
		] );

		$client = Util::initializeLiftWingClient( $this->httpRequestFactory, $config );

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
			$this->httpRequestFactory,
			$model,
			$lang,
			$expectedUrl,
			$expectedHostHeader
		);

		$config = new HashConfig( [
			'AutoModeratorLiftWingBaseUrl' => $expectedUrl,
			'AutoModeratorLiftWingAddHostHeader' => true,
			'AutoModeratorLiftWingRevertRiskHostHeader' => "another-host-header",
			'AutoModeratorLiftWingMultiLingualRevertRiskHostHeader' => $expectedHostHeader,
			'AutoModeratorWikiId' => 'enwiki',
			'AutoModeratorMultiLingualRevertRisk' => true,
			'AutoModeratorMultilingualConfigEnableMultilingual' => true,
			'AutoModeratorMultilingualConfigMultilingualThreshold' => 0.987,
			'AutoModeratorMultilingualConfigEnableLanguageAgnostic' => false,
		] );

		$client = Util::initializeLiftWingClient( $this->httpRequestFactory, $config );

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
		$config = new HashConfig( [
			'AutoModeratorMultiLingualRevertRisk' => true,
			'AutoModeratorMultilingualConfigEnableMultilingual' => false,
			'AutoModeratorMultilingualConfigMultilingualThreshold' => null,
		] );

		$isMultiLingualRevertRiskEnabled = Util::isMultiLingualRevertRiskEnabled( $config );

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
		$config = new HashConfig( [
			'AutoModeratorWikiId' => 'enwiki',
			'AutoModeratorMultiLingualRevertRisk' => true,
			'AutoModeratorMultilingualConfigEnableMultilingual' => true,
			'AutoModeratorMultilingualConfigMultilingualThreshold' => 0.987,
			'AutoModeratorMultilingualConfigEnableLanguageAgnostic' => false,
		] );

		$isMultiLingualRevertRiskEnabled = Util::isMultiLingualRevertRiskEnabled( $config );

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
			'AutoModeratorMultilingualConfigEnableMultilingual' => true,
			'AutoModeratorMultilingualConfigMultilingualThreshold' => 0.987,
			'AutoModeratorMultilingualConfigEnableLanguageAgnostic' => false,
		] );

		$expectedRevertRiskModelName = Util::getORESMultiLingualModelName();
		$revertRiskModelName = Util::getRevertRiskModel( $config );

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
			'AutoModeratorMultilingualConfigEnableMultilingual' => false,
			'AutoModeratorMultilingualConfigMultilingualThreshold' => null,
		] );

		$expectedRevertRiskModelName = Util::getORESLanguageAgnosticModelName();
		$revertRiskModelName = Util::getRevertRiskModel( $config );

		$this->assertSame( $expectedRevertRiskModelName, $revertRiskModelName );
	}

	/**
	 * @covers ::getMultiLingualThreshold
	 */
	public function testGetMultiLingualThreshold() {
		$config = new HashConfig( [
			'AutoModeratorMultilingualConfigEnableMultilingual' => true,
			'AutoModeratorMultilingualConfigMultilingualThreshold' => 0.987,
		] );

		$expectedThreshold = 0.987;
		$threshold = Util::getMultiLingualThreshold( $config );

		$this->assertSame( $expectedThreshold, $threshold );
	}

	/**
	 * @covers ::getEnableLogOnlyMode
	 */
	public function testGetEnableLogOnlyModeLanguageAgnostic() {
		$config = new HashConfig( [
			'AutoModeratorWikiId' => 'enwiki',
			'AutoModeratorMultiLingualRevertRisk' => false,
			'AutoModeratorEnableLogOnlyMode' => false
		] );

		$logModeEnabled = Util::getEnableLogOnlyMode( $config );

		$this->assertFalse( $logModeEnabled );
	}

	/**
	 * @covers ::getEnableLogOnlyMode
	 */
	public function testGetEnableLogOnlyModeMultilingual() {
		$config = new HashConfig( [
			'AutoModeratorWikiId' => 'enwiki',
			'AutoModeratorMultiLingualRevertRisk' => true,
			'AutoModeratorMultilingualEnableLogOnlyMode' => false
		] );

		$logModeEnabled = Util::getEnableLogOnlyMode( $config );

		$this->assertFalse( $logModeEnabled );
	}
}
