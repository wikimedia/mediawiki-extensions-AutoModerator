<?php

namespace AutoModerator\Tests;

use AutoModerator\Util;
use MediaWiki\Config\Config;
use MediaWikiUnitTestCase;

#[\AllowDynamicProperties]
class LiftWingClientTest extends MediaWikiUnitTestCase {

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
	 * @covers \AutoModerator\LiftWingClient::createErrorResponse
	 */
	public function testCreateErrorResponse() {
		$this->config->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorLiftWingBaseUrl', "example.org" ],
		] );
		$expectedErrorMessage = "an error message";
		$expectedHttpStatus = 404;

		$client = Util::initializeLiftWingClient( $this->config );

		$response = $client->createErrorResponse( $expectedHttpStatus, $expectedErrorMessage, true );

		$this->assertSame( $expectedHttpStatus, $response[ 'httpStatus' ] );
		$this->assertSame( $expectedErrorMessage, $response[ 'errorMessage' ] );
		$this->assertTrue( $response[ 'allowRetries' ] );
	}
}
