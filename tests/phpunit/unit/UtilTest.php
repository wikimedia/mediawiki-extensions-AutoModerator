<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\AutoModerator\Tests;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\AutoModerator\LiftWingClient;
use MediaWiki\Extension\AutoModerator\Util;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWikiUnitTestCase;

/**
 * @group AutoModerator
 * @group extensions
 * @covers \MediaWiki\Extension\AutoModerator\Util
 */
class UtilTest extends MediaWikiUnitTestCase {

	private HttpRequestFactory $httpRequestFactory;

	public function setUp(): void {
		$this->httpRequestFactory = $this->createMock( HttpRequestFactory::class );
	}

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
	 * When AutoModeratorLiftWingAddHostHeader false.
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
	 * When AutoModeratorLiftWingAddHostHeader true.
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
	 * When there multilingual model for that wiki is enabled.
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

	public function testIsMultiLingualRevertRiskEnabledFalse() {
		$config = new HashConfig( [
			'AutoModeratorMultiLingualRevertRisk' => true,
			'AutoModeratorMultilingualConfigEnableMultilingual' => false,
			'AutoModeratorMultilingualConfigMultilingualThreshold' => null,
		] );

		$isMultiLingualRevertRiskEnabled = Util::isMultiLingualRevertRiskEnabled( $config );

		$this->assertFalse( $isMultiLingualRevertRiskEnabled );
	}

	public function testIsWikiMultilingual() {
		$config = new HashConfig( [
			'AutoModeratorWikiId' => 'testwiki',
			'AutoModeratorMultiLingualRevertRisk' => true,
		] );

		$isWikiMultilingual = Util::isWikiMultilingual( $config );

		$this->assertTrue( $isWikiMultilingual );
	}

	public function testIsWikiMultilingualNot() {
		$config = new HashConfig( [
			'AutoModeratorWikiId' => 'testwiki',
			'AutoModeratorMultiLingualRevertRisk' => false,
		] );

		$isWikiMultilingual = Util::isWikiMultilingual( $config );

		$this->assertFalse( $isWikiMultilingual );
	}

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

	public function testGetMultiLingualThreshold() {
		$config = new HashConfig( [
			'AutoModeratorMultilingualConfigEnableMultilingual' => true,
			'AutoModeratorMultilingualConfigMultilingualThreshold' => 0.987,
		] );

		$expectedThreshold = 0.987;
		$threshold = Util::getMultiLingualThreshold( $config );

		$this->assertSame( $expectedThreshold, $threshold );
	}

	public function testGetMultiLingualThresholdFromString() {
		$config = new HashConfig( [
			'AutoModeratorMultilingualConfigMultilingualThreshold' => '0.987',
		] );

		$this->assertSame( 0.987, Util::getMultiLingualThreshold( $config ) );
	}

	public function testGetMaxRevertsLanguageAgnostic() {
		$config = new HashConfig( [
			'AutoModeratorMultiLingualRevertRisk' => false,
			'AutoModeratorUserRevertsPerPage' => '3',
		] );

		$this->assertSame( 3, Util::getMaxReverts( $config ) );
	}

	public function testGetMaxRevertsMultilingual() {
		$config = new HashConfig( [
			'AutoModeratorMultiLingualRevertRisk' => true,
			'AutoModeratorMultilingualConfigUserRevertsPerPage' => '5',
		] );

		$this->assertSame( 5, Util::getMaxReverts( $config ) );
	}

	public function testGetMaxRevertsNotConfigured() {
		$config = new HashConfig( [
			'AutoModeratorMultiLingualRevertRisk' => false,
			'AutoModeratorUserRevertsPerPage' => null,
		] );

		$this->assertSame( 0, Util::getMaxReverts( $config ) );
	}

	public function testGetEnableLogOnlyModeLanguageAgnostic() {
		$config = new HashConfig( [
			'AutoModeratorWikiId' => 'enwiki',
			'AutoModeratorMultiLingualRevertRisk' => false,
			'AutoModeratorEnableLogOnlyMode' => false
		] );

		$logModeEnabled = Util::getEnableLogOnlyMode( $config );

		$this->assertFalse( $logModeEnabled );
	}

	public function testGetEnableLogOnlyModeMultilingual() {
		$config = new HashConfig( [
			'AutoModeratorWikiId' => 'enwiki',
			'AutoModeratorMultiLingualRevertRisk' => true,
			'AutoModeratorMultilingualEnableLogOnlyMode' => false
		] );

		$logModeEnabled = Util::getEnableLogOnlyMode( $config );

		$this->assertFalse( $logModeEnabled );
	}

	public function testDoesORESSupportRevertRiskModel(): void {
		$config = new HashConfig( [
			'OresModels' => [
				'revertrisk-language-agnostic' => [ 'enabled' => false ],
				'damaging' => [ 'enabled' => true ],
				'goodfaith' => [ 'enabled' => true ],
				'reverted' => [ 'enabled' => false ],
				'articlequality' => [ 'enabled' => false ],
				'draftquality' => [ 'enabled' => false ],
			],
			'AutoModeratorMultilingualConfigEnableMultilingual' => true,
			'AutoModeratorMultilingualConfigEnableLanguageAgnostic' => false,
			'AutoModeratorMultiLingualRevertRisk' => true,
		] );
		$extensionRegistry = $this->createConfiguredMock( ExtensionRegistry::class, [
			'isLoaded' => true,
		] );

		static::assertFalse( Util::doesORESSupportRevertRiskModel( $config, $extensionRegistry ) );

		// Again, but when ORES does support multilingual.
		$config->set( 'OresModels', [
			...$config->get( 'OresModels' ),
			Util::getORESMultiLingualModelName() => [ 'enabled' => true ],
		] );

		static::assertTrue( Util::doesORESSupportRevertRiskModel( $config, $extensionRegistry ) );
	}
}
