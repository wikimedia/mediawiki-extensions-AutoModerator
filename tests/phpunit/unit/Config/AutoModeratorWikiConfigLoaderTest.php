<?php

namespace AutoModerator\Tests;

use AutoModerator\Config\AutoModeratorWikiConfigLoader;
use AutoModerator\Config\WikiPageConfig;
use IDBAccessObject;
use MediaWiki\Config\ConfigException;
use MediaWiki\Config\GlobalVarConfig;
use MediaWiki\Config\HashConfig;
use MediaWikiUnitTestCase;

/**
 * @coversDefaultClass \AutoModerator\Config\AutoModeratorWikiConfigLoader
 */
class AutoModeratorWikiConfigLoaderTest extends MediaWikiUnitTestCase {
	private function getMockWikiPageConfig() {
		return $this->createMock( WikiPageConfig::class );
	}

	/**
	 * @dataProvider provideIsWikiConfigEnabled
	 * @covers ::isWikiConfigEnabled
	 * @param bool $shouldEnable
	 */
	public function testIsWikiConfigEnabled( bool $shouldEnable ) {
		$config = new AutoModeratorWikiConfigLoader(
			$this->getMockWikiPageConfig(),
			new HashConfig( [ 'AutoModeratorEnableWikiConfig' => $shouldEnable ] )
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
				[ 'AutoModeratorEnableWikiConfig', false ],
				[ 'AutoModeratorFoo', 'global' ]
			] );

		$config = new AutoModeratorWikiConfigLoader(
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
		$this->expectExceptionMessage( 'Config key cannot be retrieved via AutoModeratorWikiConfigLoader' );

		$wikiConfig = $this->getMockWikiPageConfig();
		$wikiConfig->expects( $this->never() )->method( 'hasWithFlags' );
		$wikiConfig->expects( $this->never() )->method( 'getWithFlags' );

		$config = new AutoModeratorWikiConfigLoader(
			$wikiConfig,
			new HashConfig( [ 'AutoModeratorEnableWikiConfig' => true ] )
		);
		$config->get( 'AutoModeratorFoo' );
	}

	/**
	 * @covers ::getWithFlags
	 */
	public function testGetWithFlagsFromWiki() {
		$wikiConfig = $this->getMockWikiPageConfig();
		$wikiConfig->expects( $this->once() )->method( 'hasWithFlags' )
			->with( 'AutoModeratorEnableRevisionCheck', IDBAccessObject::READ_LATEST )
			->willReturn( true );
		$wikiConfig->expects( $this->once() )->method( 'getWithFlags' )
			->with( 'AutoModeratorEnableRevisionCheck', IDBAccessObject::READ_LATEST )
			->willReturn( false );

		$config = new AutoModeratorWikiConfigLoader(
			$wikiConfig,
			new HashConfig( [ 'AutoModeratorEnableWikiConfig' => true ] )
		);
		$this->assertFalse( $config->getWithFlags(
			'AutoModeratorEnableRevisionCheck',
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
			->with( 'AutoModeratorEnableRevisionCheck', IDBAccessObject::READ_NORMAL )
			->willReturn( false );
		$wikiConfig->expects( $this->never() )->method( 'getWithFlags' );

		$config = new AutoModeratorWikiConfigLoader(
			$wikiConfig,
			new HashConfig( [
				'AutoModeratorEnableWikiConfig' => true,
				'AutoModeratorEnableRevisionCheck' => true,
			] )
		);
		$this->assertTrue( $config->get( 'AutoModeratorEnableRevisionCheck' ) );
	}

	/**
	 * @covers ::get
	 * @covers ::getWithFlags
	 */
	public function testGetVariableNotFound() {
		$this->expectException( ConfigException::class );
		$this->expectExceptionMessage( 'Config key was not found in AutoModeratorWikiConfigLoader' );

		$wikiConfig = $this->getMockWikiPageConfig();
		$wikiConfig->expects( $this->once() )->method( 'hasWithFlags' )
			->with( 'AutoModeratorEnableRevisionCheck', IDBAccessObject::READ_NORMAL )
			->willReturn( false );
		$wikiConfig->expects( $this->never() )->method( 'getWithFlags' );

		$config = new AutoModeratorWikiConfigLoader(
			$wikiConfig,
			new HashConfig( [
				'AutoModeratorEnableWikiConfig' => true
			] )
		);
		$config->get( 'AutoModeratorEnableRevisionCheck' );
	}

	/**
	 * @covers ::has
	 * @covers ::hasWithFlags
	 */
	public function testHasDisallowedVariable() {
		$wikiConfig = $this->getMockWikiPageConfig();
		$wikiConfig->expects( $this->never() )->method( 'hasWithFlags' );

		$config = new AutoModeratorWikiConfigLoader(
			$this->getMockWikiPageConfig(),
			new HashConfig( [ 'AutoModeratorEnableWikiConfig' => true ] )
		);
		$this->assertFalse( $config->has( 'AutoModeratorFoo' ) );
	}

	/**
	 * @covers ::hasWithFlags
	 */
	public function testHasWithFlagsWiki() {
		$wikiConfig = $this->getMockWikiPageConfig();
		$wikiConfig->expects( $this->once() )->method( 'hasWithFlags' )
			->with( 'AutoModeratorEnableRevisionCheck', IDBAccessObject::READ_LATEST )
			->willReturn( true );

		$config = new AutoModeratorWikiConfigLoader(
			$wikiConfig,
			new HashConfig( [ 'AutoModeratorEnableWikiConfig' => true ] )
		);
		$this->assertTrue( $config->hasWithFlags(
			'AutoModeratorEnableRevisionCheck',
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
			->with( 'AutoModeratorEnableRevisionCheck', IDBAccessObject::READ_NORMAL )
			->willReturn( false );

		$config = new AutoModeratorWikiConfigLoader(
			$wikiConfig,
			new HashConfig( [
				'AutoModeratorEnableWikiConfig' => true,
				'AutoModeratorEnableRevisionCheck' => true,
			] )
		);
		$this->assertTrue( $config->has( 'AutoModeratorEnableRevisionCheck' ) );
	}

	/**
	 * @covers ::has
	 * @covers ::hasWithFlags
	 */
	public function testHasNotFound() {
		$wikiConfig = $this->getMockWikiPageConfig();
		$wikiConfig->expects( $this->once() )->method( 'hasWithFlags' )
			->with( 'AutoModeratorEnableRevisionCheck', IDBAccessObject::READ_NORMAL )
			->willReturn( false );

		$config = new AutoModeratorWikiConfigLoader(
			$wikiConfig,
			new HashConfig( [
				'AutoModeratorEnableWikiConfig' => true,
			] )
		);
		$this->assertFalse( $config->has( 'AutoModeratorEnableRevisionCheck' ) );
	}
}
