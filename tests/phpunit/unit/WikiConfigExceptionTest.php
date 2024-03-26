<?php

namespace AutoModerator\Tests;

use AutoModerator\WikiConfigException;
use MediaWikiUnitTestCase;
use Wikimedia\NormalizedException\NormalizedException;

/**
 * @coversDefaultClass \AutoModerator\WikiConfigException
 */
class WikiConfigExceptionTest extends MediaWikiUnitTestCase {

	/**
	 * @covers ::__construct
	 */
	public function testConstruction() {
		$exception = new WikiConfigException( 'Foo' );
		$this->assertInstanceOf( WikiConfigException::class, $exception );
		$this->assertInstanceOf( NormalizedException::class, $exception );
	}

}
