<?php

namespace AutoModerator\Tests;

use AutoModerator\Config\Validation\AutoModeratorConfigValidation;
use AutoModerator\Config\Validation\ConfigValidatorFactory;
use AutoModerator\Config\Validation\NoValidationValidator;
use AutoModerator\Config\WikiPageConfigLoader;
use MediaWiki\Api\ApiRawMessage;
use MediaWiki\Content\Content;
use MediaWiki\Content\JsonContent;
use MediaWiki\Content\WikitextContent;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\Title\TitleValue;
use MediaWiki\Utils\UrlUtils;
use MediaWikiUnitTestCase;
use MWHttpRequest;
use PHPUnit\Framework\MockObject\MockObject;
use StatusValue;
use Wikimedia\Message\MessageSpecifier;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * @covers \AutoModerator\Config\WikiPageConfigLoader
 * @covers \AutoModerator\Util::getJsonUrl
 * @covers \AutoModerator\Util::getRawUrl
 */
class WikiPageConfigLoaderTest extends MediaWikiUnitTestCase {

	private function getUrlUtils() {
		return new UrlUtils( [
			// UrlUtils throws if the relevant $wg(|Canonical|Internal) variable is null, but the old
			// implementations implicitly converted it to an empty string (presumably by mistake).
			// Preserve the old behavior for compatibility.
			UrlUtils::SERVER => 'http://local.wiki',
			UrlUtils::CANONICAL_SERVER => 'http://local.wiki',
			UrlUtils::INTERNAL_SERVER => 'http://local.wiki',
			UrlUtils::FALLBACK_PROTOCOL => 'http',
			UrlUtils::HTTPS_PORT => 443,
			UrlUtils::VALID_PROTOCOLS => [ 'http://', 'https://' ],
		] );
	}

	private function internalTestLoad(
		$titleValue, $isExternal, $localUrl, $fullUrl,
		$httpResponse, $requestFactoryExpectedInvokeCount,
		$lookupResult, $revisionLookupExpectedInvokeCount,
		$expectedData
	) {
		$cacheBag = new HashBagOStuff();
		$wanObjectCache = new WANObjectCache( [ 'cache' => $cacheBag ] );
		$requestFactory = $this->getMockRequestFactory( $fullUrl, $httpResponse,
			$requestFactoryExpectedInvokeCount );
		$revisionLookup = $this->getMockRevisionLookup( $titleValue, $lookupResult,
			$revisionLookupExpectedInvokeCount );
		$titleFactory = $this->getMockTitleFactory( $fullUrl, $localUrl, $isExternal );
		$configValidator = $this->createMock( AutoModeratorConfigValidation::class );
		$configValidator
			->expects(
				(
					$expectedData instanceof StatusValue &&
					!$expectedData->isOK()
				) ? $this->never() : $this->once() )
			->method( 'validate' )
			->willReturn( StatusValue::newGood() );
		$configValidatorFactory = $this->createMock( ConfigValidatorFactory::class );
		$configValidatorFactory
			->method( 'newConfigValidator' )
			->willReturn( $configValidator );
		$loader = new WikiPageConfigLoader(
			$wanObjectCache,
			$configValidatorFactory,
			$requestFactory,
			$revisionLookup,
			$titleFactory,
			$this->getUrlUtils(),
			// Pretend that storage is not disabled and let the mocks do the work.
			false
		);
		$data = $loader->load( $titleValue );
		$this->assertResultSame( $expectedData, $data );

		// call it again to check via the exactly(1) assertions that caching works
		$data = $loader->load( $titleValue );
		$this->assertResultSame( $expectedData, $data );
	}

	/**
	 * @dataProvider provideLoadHttp
	 */
	public function testLoadHttp( $httpResponse, $expectedData ) {
		$titleValue = new TitleValue( NS_MEDIAWIKI, 'MediaWiki:Page.json', '', 'r' );
		$isExternal = true;
		$site = 'http://remote.wiki';
		$localUrl = "/wiki/MediaWiki:Page.json?ctype=raw";
		$fullUrl = "$site$localUrl";

		$this->internalTestLoad( $titleValue, $isExternal, $localUrl, $fullUrl, $httpResponse,
			1, null, 0, $expectedData );
	}

	/**
	 * @dataProvider provideLoadLocal
	 */
	public function testLoadLocal( $lookupResult, $expectedData ) {
		$titleValue = new TitleValue( NS_MEDIAWIKI, 'MediaWiki:Page.json' );
		$isExternal = false;
		$site = 'http://local.wiki';
		$localUrl = "/wiki/MediaWiki:Page.json";
		$fullUrl = $site;

		$this->internalTestLoad( $titleValue, $isExternal, $localUrl, $fullUrl, '',
			0, $lookupResult, 1, $expectedData );
	}

	public static function provideLoadHttp() {
		return [
			'success' => [
				'response' => '{ "foo": "bar" }',
				'expected data' => [ 'foo' => 'bar' ],
			],
			'error' => [
				'response' => StatusValue::newFatal( 'foo' ),
				'expected data' => StatusValue::newFatal( 'foo' ),
			],
		];
	}

	public static function provideLoadLocal() {
		return [
			'success' => [
				'response' => new JsonContent( '{ "foo": "bar" }' ),
				'expected data' => [ 'foo' => 'bar' ],
			],
			'no such page' => [
				'response' => false,
				'expected data' => [],
			],
			'revdeleted' => [
				'response' => null,
				'expected data' => StatusValue::newFatal( new ApiRawMessage( 'x',
					'automoderator-configuration-loader-content-error' ) ),
			],
			'non-json' => [
				'response' => new WikitextContent( 'foo' ),
				'expected data' => StatusValue::newFatal( new ApiRawMessage( 'x',
					'automoderator-configuration-loader-content-error' ) ),
			],
		];
	}

	public function testSetCache() {
		$title = new TitleValue( NS_MAIN, 'X' );

		$cache = new HashBagOStuff();
		$wanCache = new WANObjectCache( [ 'cache' => $cache ] );
		$wanCache->set(
			$wanCache->makeKey( 'AutoModerator',
				'config', $title->getNamespace(), $title->getDBkey() ),
			[ 'abc' => 123 ]
		);

		$configValidatorFactory = $this->createMock( ConfigValidatorFactory::class );
		$configValidatorFactory
			->method( 'newConfigValidator' )
			->willReturn( new NoValidationValidator() );
		$loader = new WikiPageConfigLoader(
			$wanCache,
			$configValidatorFactory,
			$this->getMockRequestFactory( '', '', 0 ),
			$this->getMockRevisionLookup( $title, false, 0 ),
			$this->getMockTitleFactory( '', '', false ),
			$this->getUrlUtils(),
			// Pretend that storage is not disabled and let the mocks do the work.
			false
		);
		$this->assertSame( [ 'abc' => 123 ], $loader->load( $title ) );
	}

	/**
	 * @param string $url Should look like a real URL (have scheme, domain, path)
	 * @param string $titleText
	 * @param bool $isExternal
	 * @return Title|MockObject
	 */
	protected function getMockTitle( $url, $titleText, $isExternal = true ) {
		$title = $this->createNoOpMock( Title::class,
			[ 'getFullURL', 'getNamespace', 'getDBKey', 'isExternal' ] );
		$title->method( 'isExternal' )->willReturn( $isExternal );
		$title->method( 'getFullURL' )->willReturn( $url );
		$title->method( 'getNamespace' )->willReturn( 0 );
		$title->method( 'getDBKey' )->willReturn( $titleText );
		return $title;
	}

	/**
	 * @param string $expectedUrl
	 * @param string|Status $result A content string or an error status.
	 * @param int $invokeCount The number of times the factory is expected to be used.
	 * @return HttpRequestFactory|MockObject
	 */
	protected function getMockRequestFactory( $expectedUrl, $result, $invokeCount = 1 ) {
		$content = null;
		if ( !( $result instanceof StatusValue ) ) {
			$content = $result;
			$result = Status::newGood();
		}

		$request = $this->createNoOpMock( MWHttpRequest::class, [ 'execute', 'getContent' ] );
		$request->method( 'execute' )->willReturn( $result );
		$request->method( 'getContent' )->willReturn( $content );

		$requestFactory = $this->createNoOpMock( HttpRequestFactory::class,
			[ 'create', 'getUserAgent' ] );
		$requestFactory->method( 'getUserAgent' )->willReturn( 'Foo' );
		$requestFactory->expects( $this->exactly( $invokeCount ) )
			->method( 'create' )
			->with( $expectedUrl, $this->anything(), $this->anything() )
			->willReturn( $request );
		return $requestFactory;
	}

	/**
	 * @param LinkTarget $expectedTitle
	 * @param false|null|Content $content A content object, or false for revision lookup
	 *   failure, or null for slot access failure.
	 * @param int $invokeCount The number of times the factory is expected to be used.
	 * @return RevisionLookup|MockObject
	 */
	protected function getMockRevisionLookup( LinkTarget $expectedTitle, $content, $invokeCount = 1 ) {
		$revisionLookup = $this->createMock( RevisionLookup::class );
		$revisionLookup->expects( $this->exactly( $invokeCount ) )
			->method( 'getRevisionByTitle' )
			->willReturnCallback( function ( LinkTarget $title ) use ( $expectedTitle, $content ) {
				$this->assertSame( $expectedTitle->getNamespace(), $title->getNamespace() );
				$this->assertSame( $expectedTitle->getText(), $title->getText() );
				$this->assertSame( $expectedTitle->getInterwiki(), $title->getInterwiki() );
				if ( $content === false ) {
					return null;
				}
				$revision = $this->createMock( RevisionRecord::class );
				$revision->expects( $this->once() )
					->method( 'getContent' )
					->willReturn( $content );
				return $revision;
			} );
		return $revisionLookup;
	}

	/**
	 * Works around the URL handling ugliness in PageConfigurationLoader::getRawUrl.
	 * @param string $fullUrl
	 * @param string $localUrl
	 * @param bool $isExternal
	 * @return TitleFactory|MockObject
	 */
	protected function getMockTitleFactory( $fullUrl, $localUrl, $isExternal ) {
		$titleFactory = $this->createNoOpMock( TitleFactory::class,
			[ 'newFromLinkTarget', 'makeTitle' ] );
		$title = $this->createNoOpMock( Title::class, [ 'getFullURL', 'getLocalURL', 'isExternal' ] );
		$titleFactory->method( 'newFromLinkTarget' )
			->willReturn( $title );
		$titleFactory->method( 'makeTitle' )
			->willReturn( $title );
		$title->method( 'getFullURL' )
			->willReturn( $fullUrl );
		$title->method( 'getLocalURL' )
			->willReturn( $localUrl );
		$title->method( 'isExternal' )
			->willReturn( $isExternal );
		return $titleFactory;
	}

	/**
	 * Assert that a result (JSON array or error status) matches the expected value.
	 * @param array|StatusValue $expectedData
	 * @param array|StatusValue $data
	 */
	private function assertResultSame( $expectedData, $data ) {
		if ( $expectedData instanceof StatusValue ) {
			$this->assertInstanceOf( StatusValue::class, $data );
			foreach ( $expectedData->getErrors() as [ 'message' => $expectedMessage ] ) {
				if ( $expectedMessage instanceof ApiRawMessage ) {
					// avoid breaking the test on wording changes, as long as the error code matches
					$code = $expectedMessage->getApiCode();
					$this->assertNotEmpty(
						array_filter( $data->getErrors(), static function ( $error ) use ( $code ) {
							return $error['message'] instanceof ApiRawMessage
								&& $error['message']->getApiCode() === $code;
						} ),
						"error result did not have message with code $code: $data"
					);
				} else {
					$key = $expectedMessage instanceof MessageSpecifier
						? $expectedMessage->getKey() : $expectedMessage;
					$this->assertTrue( $data->hasMessage( $expectedMessage ),
						"error result did not have message with key $key: $data" );
				}
			}
		} else {
			$this->assertIsArray( $data );
			$this->assertEquals( $expectedData, $data );
		}
	}
}
