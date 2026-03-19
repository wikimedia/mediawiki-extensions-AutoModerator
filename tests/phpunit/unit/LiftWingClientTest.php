<?php

namespace AutoModerator\Tests;

use AutoModerator\Util;
use MediaWiki\Config\HashConfig;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Http\MWHttpRequest;
use MediaWiki\Status\Status;
use MediaWikiUnitTestCase;

class LiftWingClientTest extends MediaWikiUnitTestCase {
	/** @var config */
	private $config;
	/** @var HttpRequestFactory */
	private $httpRequestFactory;

	public function setUp(): void {
		$this->config = new HashConfig( [
			'AutoModeratorLiftWingBaseUrl' => 'http://example.com/',
			'AutoModeratorWikiId' => "idwiki",
			'AutoModeratorLiftWingAddHostHeader' => false,
		] );
		$this->httpRequestFactory = $this->createMock( HttpRequestFactory::class );
		$httpRequestMock = $this->createMock( MWHttpRequest::class );
		$statusMock = $this->createMock( Status::class );
		$statusMock->method( 'isOk' )->willReturn( true );
		$httpRequestMock->method( 'execute' )->willReturn( $statusMock );
		$response = '{"data": "" }';
		$httpRequestMock->method( 'getContent' )->willReturn( $response );
		$this->httpRequestFactory->method( 'create' )->willReturn( $httpRequestMock );
	}

	/**
	 * @covers \AutoModerator\LiftWingClient::getBaseUrl
	 */
	public function testGetBaseUrl() {
		$client = Util::initializeLiftWingClient( $this->httpRequestFactory, $this->config );
		$this->assertEquals( 'http://example.com/', $client->getBaseUrl() );
	}

	/**
	 * @covers \AutoModerator\LiftWingClient::getUserAgent
	 */
	public function testGetUserAgentHeader() {
		$client = Util::initializeLiftWingClient( $this->httpRequestFactory, $this->config );
		$this->assertEquals( 'mediawiki.ext.AutoModerator.id', $client->getUserAgent() );
	}

	/**
	 * @covers \AutoModerator\LiftWingClient::getHostHeader
	 */
	public function testGetHostHeader() {
		// no host header
		$client = Util::initializeLiftWingClient( $this->httpRequestFactory, $this->config );
		$this->assertNull( $client->getHostHeader() );
		// language agnostic host heaeder
		$this->config->set( 'AutoModeratorLiftWingAddHostHeader', true );
		$this->config->set( 'AutoModeratorLiftWingRevertRiskHostHeader', 'la.revertrisk.example.com' );
		$client = Util::initializeLiftWingClient( $this->httpRequestFactory, $this->config );
		$this->assertEquals( 'la.revertrisk.example.com', $client->getHostHeader() );
		// multilingual host header
		$this->config->set( 'AutoModeratorMultiLingualRevertRisk', true );
		$this->config->set( 'AutoModeratorMultilingualConfigEnableLanguageAgnostic', false );
		$this->config->set( 'AutoModeratorMultilingualConfigEnableMultilingual', true );
		$this->config->set( 'AutoModeratorLiftWingMultiLingualRevertRiskHostHeader', 'ml.revertrisk.example.com' );
		$client = Util::initializeLiftWingClient( $this->httpRequestFactory, $this->config );
		$this->assertEquals( 'ml.revertrisk.example.com', $client->getHostHeader() );
	}

	/**
	 * @covers \AutoModerator\LiftWingClient::get
	 */
	public function testGet() {
		$client = Util::initializeLiftWingClient( $this->httpRequestFactory, $this->config );
		$expected = [ 'data' => '' ];
		$this->assertEquals( $expected, $client->get( 0 ) );
	}
}
