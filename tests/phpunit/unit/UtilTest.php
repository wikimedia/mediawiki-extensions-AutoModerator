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
		$config = $this->createMock( Config::class );
		$config->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorUsername', 'AutoModerator' ],
			[ 'AutoModeratorWikiId', 'enwiki' ],
			[ 'AutoModeratorMultiLingualRevertRisk',
				[
					"enabled" => true,
					"thresholds" => [
						"very-cautious" => 0.990,
						"cautious" => 0.980,
						"somewhat-cautious" => 0.970,
						"less-cautious" => 0.960
					]
				]
			],
		] );
		$revertThreshold = Util::getRevertThreshold( $wikiConfig, $config, 'revertrisklanguageagnostic' );
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
		] );
		$config = $this->createMock( Config::class );
		$config->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorUsername', 'AutoModerator' ],
			[ 'AutoModeratorWikiId', 'enwiki' ],
			[ 'AutoModeratorMultiLingualRevertRisk',
				[
					"enabled" => true,
					"thresholds" => [
						"very-cautious" => 0.990,
						"cautious" => 0.980,
						"somewhat-cautious" => 0.970,
						"less-cautious" => 0.960
					]
				]
			],
		] );
		$revertThreshold = Util::getRevertThreshold( $wikiConfig, $config, 'revertrisklanguageagnostic' );
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
		$config = $this->createMock( Config::class );
		$config->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorUsername', 'AutoModerator' ],
			[ 'AutoModeratorWikiId', 'enwiki' ],
			[ 'AutoModeratorMultiLingualRevertRisk',
				[
					"enabled" => true,
					"thresholds" => [
						"very-cautious" => 0.990,
						"cautious" => 0.980,
						"somewhat-cautious" => 0.970,
						"less-cautious" => 0.960
					]
				]
			],
		] );
		$revertThreshold = Util::getRevertThreshold( $wikiConfig, $config, 'revertrisklanguageagnostic' );
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
		$config = $this->createMock( Config::class );
		$config->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorUsername', 'AutoModerator' ],
			[ 'AutoModeratorWikiId', 'enwiki' ],
			[ 'AutoModeratorMultiLingualRevertRisk',
				[
					"enabled" => true,
					"thresholds" => [
						"very-cautious" => 0.990,
						"cautious" => 0.980,
						"somewhat-cautious" => 0.970,
						"less-cautious" => 0.960
					]
				]
			],
		] );
		$revertThreshold = Util::getRevertThreshold( $wikiConfig, $config, 'revertrisklanguageagnostic' );
		$this->assertSame(
			0.975,
			$revertThreshold
		);
	}

	/**
	 * @covers ::getRevertThreshold cautious
	 */
	public function testGetRevertThresholdVeryCautiousMultiLingual() {
		$wikiConfig = $this->createMock( Config::class );
		$wikiConfig->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorCautionLevel', 'very-cautious' ],
		] );
		$config = $this->createMock( Config::class );
		$config->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorUsername', 'AutoModerator' ],
			[ 'AutoModeratorWikiId', 'enwiki' ],
			[ 'AutoModeratorMultiLingualRevertRisk',
				[
					"enabled" => true,
					"thresholds" => [
						"very-cautious" => 0.990,
						"cautious" => 0.980,
						"somewhat-cautious" => 0.970,
						"less-cautious" => 0.960
					]
				]
			],
		] );
		$revertThreshold = Util::getRevertThreshold( $wikiConfig, $config, 'revertriskmultilingual' );
		$this->assertSame(
			0.990,
			$revertThreshold
		);
	}

	/**
	 * @covers ::getRevertThreshold cautious
	 */
	public function testGetRevertThresholdCautiousMultiLingual() {
		$wikiConfig = $this->createMock( Config::class );
		$wikiConfig->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorCautionLevel', 'cautious' ],
		] );
		$config = $this->createMock( Config::class );
		$config->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorUsername', 'AutoModerator' ],
			[ 'AutoModeratorWikiId', 'enwiki' ],
			[ 'AutoModeratorMultiLingualRevertRisk',
				[
					"enabled" => true,
					"thresholds" => [
						"very-cautious" => 0.990,
						"cautious" => 0.980,
						"somewhat-cautious" => 0.970,
						"less-cautious" => 0.960
					]
				]
			],
		] );
		$revertThreshold = Util::getRevertThreshold( $wikiConfig, $config, 'revertriskmultilingual' );
		$this->assertSame(
			0.980,
			$revertThreshold
		);
	}

	/**
	 * @covers ::getRevertThreshold somewhat-cautious
	 */
	public function testGetRevertThresholdSomewhatCautiousMultiLingual() {
		$wikiConfig = $this->createMock( Config::class );
		$wikiConfig->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorCautionLevel', 'somewhat-cautious' ],
		] );
		$config = $this->createMock( Config::class );
		$config->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorUsername', 'AutoModerator' ],
			[ 'AutoModeratorWikiId', 'enwiki' ],
			[ 'AutoModeratorMultiLingualRevertRisk',
				[
					"enabled" => true,
					"thresholds" => [
						"very-cautious" => 0.990,
						"cautious" => 0.980,
						"somewhat-cautious" => 0.970,
						"less-cautious" => 0.960
					]
				]
			],
		] );
		$revertThreshold = Util::getRevertThreshold( $wikiConfig, $config, 'revertriskmultilingual' );
		$this->assertSame(
			0.970,
			$revertThreshold
		);
	}

	/**
	 * @covers ::getRevertThreshold less-cautious
	 */
	public function testGetRevertThresholdLessCautiousMultiLingual() {
		$wikiConfig = $this->createMock( Config::class );
		$wikiConfig->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorCautionLevel', 'less-cautious' ],
		] );
		$config = $this->createMock( Config::class );
		$config->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorUsername', 'AutoModerator' ],
			[ 'AutoModeratorWikiId', 'enwiki' ],
			[ 'AutoModeratorMultiLingualRevertRisk',
				[
					"enabled" => true,
					"thresholds" => [
						"very-cautious" => 0.990,
						"cautious" => 0.980,
						"somewhat-cautious" => 0.970,
						"less-cautious" => 0.960
					]
				]
			],
		] );
		$revertThreshold = Util::getRevertThreshold( $wikiConfig, $config, 'revertriskmultilingual' );
		$this->assertSame(
			0.960,
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
			[ 'AutoModeratorMultiLingualRevertRisk',
				[
					"enabled" => true,
					"thresholds" => [
						"very-cautious" => 0.990,
						"cautious" => 0.980,
						"somewhat-cautious" => 0.970,
						"less-cautious" => 0.960
					]
				]
			],
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
			[ 'AutoModeratorWikiId', 'enwiki' ],
			[ 'AutoModeratorMultiLingualRevertRisk',
				[
					"enabled" => true,
					"thresholds" => [
						"very-cautious" => 0.990,
						"cautious" => 0.980,
						"somewhat-cautious" => 0.970,
						"less-cautious" => 0.960
					]
				]
			],
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
			[ 'AutoModeratorMultiLingualRevertRisk',
				[
					"enabled" => true,
					"thresholds" => [
						"very-cautious" => 0.990,
						"cautious" => 0.980,
						"somewhat-cautious" => 0.970,
						"less-cautious" => 0.960
					]
			]
			],
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

	/**
	 * @covers ::isMultiLingualRevertRiskEnabled
	 */
	public function testIsMultiLingualRevertRiskEnabledFalse() {
		$config = $this->createMock( Config::class );
		$config->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorMultiLingualRevertRisk',
				[
					"enabled" => false,
					"thresholds" => [
						"very-cautious" => 0.990,
						"cautious" => 0.980,
						"somewhat-cautious" => 0.970,
						"less-cautious" => 0.960
					]
				]
			],
		] );

		$isMultiLingualRevertRiskEnabled = Util::isMultiLingualRevertRiskEnabled( $config );

		$this->assertFalse( $isMultiLingualRevertRiskEnabled );
	}

	/**
	 * @covers ::isMultiLingualRevertRiskEnabled
	 */
	public function testIsMultiLingualRevertRiskEnabledTrue() {
		$config = $this->createMock( Config::class );
		$config->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorWikiId', 'enwiki' ],
			[ 'AutoModeratorMultiLingualRevertRisk',
				[
					"enabled" => true,
					"thresholds" => [
						"very-cautious" => 0.990,
						"cautious" => 0.980,
						"somewhat-cautious" => 0.970,
						"less-cautious" => 0.960
					],
				]
			],
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
		$config = $this->createMock( Config::class );
		$config->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorWikiId', $wikiId ],
			[ 'AutoModeratorMultiLingualRevertRisk',
				[
					"enabled" => true,
					"thresholds" => [
						"very-cautious" => 0.990,
						"cautious" => 0.980,
						"somewhat-cautious" => 0.970,
						"less-cautious" => 0.960
					]
				]
			],
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
		$config = $this->createMock( Config::class );
		$config->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorWikiId', $wikiId ],
			[ 'AutoModeratorMultiLingualRevertRisk',
				[
					"enabled" => false,
					"thresholds" => [
						"very-cautious" => 0.990,
						"cautious" => 0.980,
						"somewhat-cautious" => 0.970,
						"less-cautious" => 0.960
					]
				]
			],
		] );

		$expectedRevertRiskModelName = Util::getORESLanguageAgnosticModelName();
		$revertRiskModelName = Util::getRevertRiskModel( $config );

		$this->assertSame( $expectedRevertRiskModelName, $revertRiskModelName );
	}

	/**
	 * @covers ::getMultiLingualThresholds
	 */
	public function testGetMultiLingualThresholds() {
		$wikiId = "enwiki";
		$config = $this->createMock( Config::class );
		$config->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorWikiId', $wikiId ],
			[ 'AutoModeratorMultiLingualRevertRisk',
				[
					"enabled" => true,
					"thresholds" => [
						"very-cautious" => 0.990,
						"cautious" => 0.980,
						"somewhat-cautious" => 0.970,
						"less-cautious" => 0.960
					]
				]
			],
		] );

		$expectedThresholds = [
			"very-cautious" => 0.990,
			"cautious" => 0.980,
			"somewhat-cautious" => 0.970,
			"less-cautious" => 0.960
		];
		$thresholds = Util::getMultiLingualThresholds( $config );

		$this->assertSame( $expectedThresholds, $thresholds );
	}
}
