<?php

namespace AutoModerator\Tests;

use AutoModerator\Util;
use MediaWiki\Config\Config;
use MediaWikiUnitTestCase;

class LiftWingClientTest extends MediaWikiUnitTestCase {

	/**
	 * @covers \AutoModerator\LiftWingClient::createErrorResponse
	 */
	public function testCreateErrorResponse() {
		$config = $this->createMock( Config::class );
		$config->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorLiftWingBaseUrl', "example.org" ],
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
		$expectedErrorMessage = "an error message";
		$expectedHttpStatus = 404;

		$client = Util::initializeLiftWingClient( $config );

		$response = $client->createErrorResponse( $expectedHttpStatus, $expectedErrorMessage, true );

		$this->assertSame( $expectedHttpStatus, $response[ 'httpStatus' ] );
		$this->assertSame( $expectedErrorMessage, $response[ 'errorMessage' ] );
		$this->assertTrue( $response[ 'allowRetries' ] );
	}

	/**
	 * @covers \AutoModerator\LiftWingClient::getUserAgent
	 */
	public function testGetUserAgentHeader() {
		$config = $this->createMock( Config::class );
		$config->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorLiftWingBaseUrl', "example.org" ],
			[ 'AutoModeratorWikiId', "idwiki" ],
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

		$this->assertEquals( 'mediawiki.ext.AutoModerator.id', $client->getUserAgent() );
	}
}
