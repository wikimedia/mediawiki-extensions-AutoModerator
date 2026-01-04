<?php

namespace AutoModerator\Tests;

use AutoModerator\Util;
use MediaWiki\Config\HashConfig;
use MediaWikiUnitTestCase;

class LiftWingClientTest extends MediaWikiUnitTestCase {

	/**
	 * @covers \AutoModerator\LiftWingClient::createErrorResponse
	 */
	public function testCreateErrorResponse() {
		$config = new HashConfig( [
			'AutoModeratorLiftWingBaseUrl' => "example.org",
			'AutoModeratorWikiId' => "idwiki",
			'AutoModeratorMultiLingualRevertRisk' => true,
			'AutoModeratorLiftWingAddHostHeader' => false,
		] );
		$wikiConfig = new HashConfig( [
			'AutoModeratorMultilingualConfigEnableMultilingual' => false,
			'AutoModeratorMultilingualConfigMultilingualThreshold' => null,
		] );
		$expectedErrorMessage = "an error message";
		$expectedHttpStatus = 404;

		$client = Util::initializeLiftWingClient( $config, $wikiConfig );

		$response = $client->createErrorResponse( $expectedHttpStatus, $expectedErrorMessage, true );

		$this->assertSame( $expectedHttpStatus, $response[ 'httpStatus' ] );
		$this->assertSame( $expectedErrorMessage, $response[ 'errorMessage' ] );
		$this->assertTrue( $response[ 'allowRetries' ] );
	}

	/**
	 * @covers \AutoModerator\LiftWingClient::getUserAgent
	 */
	public function testGetUserAgentHeader() {
		$config = new HashConfig( [
			'AutoModeratorLiftWingBaseUrl' => "example.org",
			'AutoModeratorWikiId' => "idwiki",
			'AutoModeratorMultiLingualRevertRisk' => true,
			'AutoModeratorLiftWingAddHostHeader' => false,
		] );
		$wikiConfig = new HashConfig( [
			'AutoModeratorMultilingualConfigEnableMultilingual' => false,
			'AutoModeratorMultilingualConfigMultilingualThreshold' => null,
		] );

		$client = Util::initializeLiftWingClient( $config, $wikiConfig );

		$this->assertEquals( 'mediawiki.ext.AutoModerator.id', $client->getUserAgent() );
	}
}
