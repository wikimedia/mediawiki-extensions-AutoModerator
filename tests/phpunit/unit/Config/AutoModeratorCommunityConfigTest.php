<?php

namespace AutoModerator\Tests;

use AutoModerator\Config\AutoModeratorCommunityConfig;
use AutoModerator\Config\WikiPageConfig;
use IDBAccessObject;
use MediaWiki\Config\ConfigException;
use MediaWiki\Config\GlobalVarConfig;
use MediaWiki\Config\HashConfig;
use MediaWikiUnitTestCase;

/**
 * @coversDefaultClass \AutoModerator\Config\AutoModeratorCommunityConfig
 */
class AutoModeratorCommunityConfigTest extends MediaWikiUnitTestCase {
	private function getMockWikiPageConfig() {
		return $this->createMock( WikiPageConfig::class );
	}

	/**
	 * @dataProvider provideIsWikiConfigEnabled
	 * @covers ::isWikiConfigEnabled
	 * @param bool $shouldEnable
	 */
	public function testIsWikiConfigEnabled( bool $shouldEnable ) {
		$config = new AutoModeratorCommunityConfig(
			$this->getMockWikiPageConfig(),
			new HashConfig( [ 'AutoModeratorWikiConfigEnabled' => $shouldEnable ] )
		);
		$this->assertSame( $shouldEnable, $config->isWikiConfigEnabled() );
	}

	public static function provideIsWikiConfigEnabled() {
		return [
			'enabled' => [ true ],
			'disabled' => [ false ],
		];
	}

	/**
	 * @covers ::get
	 * @covers ::getWithFlags
	 */
	public function testGetConfigDisabled() {
		$wikiConfig = $this->getMockWikiPageConfig();
		$wikiConfig->expects( $this->never() )->method( 'getWithFlags' );

		$globalVarConfig = $this->createMock( GlobalVarConfig::class );
		$globalVarConfig->expects( $this->exactly( 2 ) )->method( 'get' )
			->willReturnMap( [
				[ 'AutoModeratorWikiConfigEnabled', false ],
				[ 'AutoModeratorFoo', 'global' ]
			] );

		$config = new AutoModeratorCommunityConfig(
			$wikiConfig,
			$globalVarConfig
		);
		$this->assertEquals( 'global', $config->get( 'AutoModeratorFoo' ) );
	}

	/**
	 * @covers ::get
	 * @covers ::getWithFlags
	 */
	public function testGetDisallowedVariable() {
		$this->expectException( ConfigException::class );
		$this->expectExceptionMessage( 'Config key cannot be retrieved via AutoModeratorCommunityConfig' );

		$wikiConfig = $this->getMockWikiPageConfig();
		$wikiConfig->expects( $this->never() )->method( 'hasWithFlags' );
		$wikiConfig->expects( $this->never() )->method( 'getWithFlags' );

		$config = new AutoModeratorCommunityConfig(
			$wikiConfig,
			new HashConfig( [ 'AutoModeratorWikiConfigEnabled' => true ] )
		);
		$config->get( 'AutoModeratorFoo' );
	}

	/**
	 * @covers ::getWithFlags
	 */
	public function testGetWithFlagsFromWiki() {
		$wikiConfig = $this->getMockWikiPageConfig();
		$wikiConfig->expects( $this->once() )->method( 'hasWithFlags' )
			->with( 'AutoModeratorEnable', IDBAccessObject::READ_LATEST )
			->willReturn( true );
		$wikiConfig->expects( $this->once() )->method( 'getWithFlags' )
			->with( 'AutoModeratorEnable', IDBAccessObject::READ_LATEST )
			->willReturn( false );

		$config = new AutoModeratorCommunityConfig(
			$wikiConfig,
			new HashConfig( [ 'AutoModeratorWikiConfigEnabled' => true ] )
		);
		$this->assertFalse( $config->getWithFlags(
			'AutoModeratorEnable',
			IDBAccessObject::READ_LATEST
		) );
	}

	/**
	 * @covers ::get
	 * @covers ::getWithFlags
	 */
	public function testGetFromGlobal() {
		$wikiConfig = $this->getMockWikiPageConfig();
		$wikiConfig->expects( $this->once() )->method( 'hasWithFlags' )
			->with( 'AutoModeratorEnable', IDBAccessObject::READ_NORMAL )
			->willReturn( false );
		$wikiConfig->expects( $this->never() )->method( 'getWithFlags' );

		$config = new AutoModeratorCommunityConfig(
			$wikiConfig,
			new HashConfig( [
				'AutoModeratorWikiConfigEnabled' => true,
				'AutoModeratorEnable' => true,
			] )
		);
		$this->assertTrue( $config->get( 'AutoModeratorEnable' ) );
	}

	/**
	 * @covers ::get
	 * @covers ::getWithFlags
	 */
	public function testGetVariableNotFound() {
		$this->expectException( ConfigException::class );
		$this->expectExceptionMessage( 'Config key was not found in AutoModeratorCommunityConfig' );

		$wikiConfig = $this->getMockWikiPageConfig();
		$wikiConfig->expects( $this->once() )->method( 'hasWithFlags' )
			->with( 'AutoModeratorEnable', IDBAccessObject::READ_NORMAL )
			->willReturn( false );
		$wikiConfig->expects( $this->never() )->method( 'getWithFlags' );

		$config = new AutoModeratorCommunityConfig(
			$wikiConfig,
			new HashConfig( [
				'AutoModeratorWikiConfigEnabled' => true
			] )
		);
		$config->get( 'AutoModeratorEnable' );
	}

	/**
	 * @covers ::has
	 * @covers ::hasWithFlags
	 */
	public function testHasDisallowedVariable() {
		$wikiConfig = $this->getMockWikiPageConfig();
		$wikiConfig->expects( $this->never() )->method( 'hasWithFlags' );

		$config = new AutoModeratorCommunityConfig(
			$this->getMockWikiPageConfig(),
			new HashConfig( [ 'AutoModeratorWikiConfigEnabled' => true ] )
		);
		$this->assertFalse( $config->has( 'AutoModeratorFoo' ) );
	}

	/**
	 * @covers ::hasWithFlags
	 */
	public function testHasWithFlagsWiki() {
		$wikiConfig = $this->getMockWikiPageConfig();
		$wikiConfig->expects( $this->once() )->method( 'hasWithFlags' )
			->with( 'AutoModeratorEnable', IDBAccessObject::READ_LATEST )
			->willReturn( true );

		$config = new AutoModeratorCommunityConfig(
			$wikiConfig,
			new HashConfig( [ 'AutoModeratorWikiConfigEnabled' => true ] )
		);
		$this->assertTrue( $config->hasWithFlags(
			'AutoModeratorEnable',
			IDBAccessObject::READ_LATEST
		) );
	}

	/**
	 * @covers ::has
	 * @covers ::hasWithFlags
	 */
	public function testHasGlobal() {
		$wikiConfig = $this->getMockWikiPageConfig();
		$wikiConfig->expects( $this->once() )->method( 'hasWithFlags' )
			->with( 'AutoModeratorEnable', IDBAccessObject::READ_NORMAL )
			->willReturn( false );

		$config = new AutoModeratorCommunityConfig(
			$wikiConfig,
			new HashConfig( [
				'AutoModeratorWikiConfigEnabled' => true,
				'AutoModeratorEnable' => true,
			] )
		);
		$this->assertTrue( $config->has( 'AutoModeratorEnable' ) );
	}

	/**
	 * @covers ::has
	 * @covers ::hasWithFlags
	 */
	public function testHasNotFound() {
		$wikiConfig = $this->getMockWikiPageConfig();
		$wikiConfig->expects( $this->once() )->method( 'hasWithFlags' )
			->with( 'AutoModeratorEnable', IDBAccessObject::READ_NORMAL )
			->willReturn( false );

		$config = new AutoModeratorCommunityConfig(
			$wikiConfig,
			new HashConfig( [
				'AutoModeratorWikiConfigEnabled' => true,
			] )
		);
		$this->assertFalse( $config->has( 'AutoModeratorEnable' ) );
	}
}
