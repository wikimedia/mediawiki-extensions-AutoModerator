<?php

namespace AutoModerator\Tests;

use AutoModerator\Services\AutoModeratorRollback;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Config\Config;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Content\ContentHandler;
use MediaWiki\MainConfigNames;
use MediaWiki\Revision\SlotRecord;
use MockHttpTrait;

/**
 * @group Database
 * @covers AutoModerator\Services\AutoModeratorRollback
 */
class AutoModeratorRollbackTest extends \MediaWikiIntegrationTestCase {
	use MockHttpTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->markTestSkipped( "" );
	}

	/**
	 * @group Database
	 * @covers AutoModerator\Services\AutoModeratorRollback::rollback
	 */
	public function testRollback() {
		$mediaWikiServices = $this->getServiceContainer();
		$title = $mediaWikiServices->getTitleFactory()->newFromText( "Title" );
		$wikiPage = $mediaWikiServices->getWikiPageFactory()->newFromTitle( $title );
		$config = $this->createMock( Config::class );
		$config->method( "has" )->willReturn( true );
		$config->method( "get" )->willReturn( [ [ MainConfigNames::UseRCPatrol, true ],
			[ MainConfigNames::DisableAnonTalk, true ] ] );

		$wikiConfig = $this->createMock( Config::class );

		$expectedContentAfterRollback = 'any new text';
		$mediaWikiServices
			->getPageUpdaterFactory()
			->newPageUpdater( $wikiPage, self::getTestSysop()->getUser() )
			->setContent( SlotRecord::MAIN, ContentHandler::makeContent( $expectedContentAfterRollback, $title ) )
			->saveRevision( CommentStoreComment::newUnsavedComment( "comment" ) );

		$mediaWikiServices
			->getPageUpdaterFactory()
			->newPageUpdater( $wikiPage, self::getTestUser()->getUser() )
			->setContent( SlotRecord::MAIN, ContentHandler::makeContent( 'any new text2', $title ) )
			->saveRevision( CommentStoreComment::newUnsavedComment( "comment2" ) );

		$mediaWikiServices
			->getPageUpdaterFactory()
			->newPageUpdater( $wikiPage, self::getTestUser()->getUser() )
			->setContent( SlotRecord::MAIN, ContentHandler::makeContent( 'any new text3', $title ) )
			->saveRevision( CommentStoreComment::newUnsavedComment( "comment3" ) );

		$mediaWikiServices
			->getPageUpdaterFactory()
			->newPageUpdater( $wikiPage, self::getTestUser()->getUser() )
			->setContent( SlotRecord::MAIN, ContentHandler::makeContent( 'any new text4', $title ) )
			->saveRevision( CommentStoreComment::newUnsavedComment( "comment4" ) );

		$rollbackPage =	new AutoModeratorRollback(
			new ServiceOptions( AutoModeratorRollback::CONSTRUCTOR_OPTIONS, $config ),
			$mediaWikiServices->getDBLoadBalancerFactory(),
			$mediaWikiServices->getRevisionStore(),
			$mediaWikiServices->getTitleFormatter(),
			$mediaWikiServices->getHookContainer(),
			$mediaWikiServices->getWikiPageFactory(),
			$mediaWikiServices->getActorMigration(),
			$mediaWikiServices->getActorNormalization(),
			$wikiPage,
			self::getTestSysop()->getUser(),
			self::getTestUser()->getUser(),
			$config,
			$wikiConfig,
		);

		$rollbackPage->rollback();
		$latestRevisionRecord = $wikiPage->getContent()->getWikitextForTransclusion();

		$this->assertSame( $expectedContentAfterRollback, $latestRevisionRecord );
	}

	/**
	 * @group Database
	 * @covers AutoModerator\Services\AutoModeratorRollback::rollback
	 */
	public function testRollbackNoAction() {
		$mediaWikiServices = $this->getServiceContainer();
		$title = $mediaWikiServices->getTitleFactory()->newFromText( "Title" );
		$wikiPage = $mediaWikiServices->getWikiPageFactory()->newFromTitle( $title );
		$config = $this->createMock( Config::class );
		$config->method( "has" )->willReturn( true );
		$config->method( "get" )->willReturn( [ [ MainConfigNames::UseRCPatrol, true ],
			[ MainConfigNames::DisableAnonTalk, true ] ] );

		$wikiConfig = $this->createMock( Config::class );

		$contentAfterRollback = 'any new text';
		$mediaWikiServices
			->getPageUpdaterFactory()
			->newPageUpdater( $wikiPage, self::getTestSysop()->getUser() )
			->setContent( SlotRecord::MAIN, ContentHandler::makeContent( $contentAfterRollback, $title ) )
			->saveRevision( CommentStoreComment::newUnsavedComment( "comment" ) );

		$mediaWikiServices
			->getPageUpdaterFactory()
			->newPageUpdater( $wikiPage, self::getTestUser()->getUser() )
			->setContent( SlotRecord::MAIN, ContentHandler::makeContent( 'any new text2', $title ) )
			->saveRevision( CommentStoreComment::newUnsavedComment( "comment2" ) );

		$mediaWikiServices
			->getPageUpdaterFactory()
			->newPageUpdater( $wikiPage, self::getTestUser()->getUser() )
			->setContent( SlotRecord::MAIN, ContentHandler::makeContent( 'any new text3', $title ) )
			->saveRevision( CommentStoreComment::newUnsavedComment( "comment3" ) );

		$expectedContent = 'any new text4';
		$mediaWikiServices
			->getPageUpdaterFactory()
			->newPageUpdater( $wikiPage, self::getTestUser()->getUser() )
			->setContent( SlotRecord::MAIN, ContentHandler::makeContent( $expectedContent, $title ) )
			->saveRevision( CommentStoreComment::newUnsavedComment( "comment4" ) );

		$rollbackPage =	new AutoModeratorRollback(
			new ServiceOptions( AutoModeratorRollback::CONSTRUCTOR_OPTIONS, $config ),
			$mediaWikiServices->getDBLoadBalancerFactory(),
			$mediaWikiServices->getRevisionStore(),
			$mediaWikiServices->getTitleFormatter(),
			$mediaWikiServices->getHookContainer(),
			$mediaWikiServices->getWikiPageFactory(),
			$mediaWikiServices->getActorMigration(),
			$mediaWikiServices->getActorNormalization(),
			$wikiPage,
			self::getTestUser()->getUser(),
			self::getTestSysop()->getUser(),
			$config,
			$wikiConfig,
		);

		$rollbackPage->rollback();
		$latestRevisionRecord = $wikiPage->getContent()->getWikitextForTransclusion();

		$this->assertNotSame( $contentAfterRollback, $latestRevisionRecord );
		$this->assertSame( $expectedContent, $latestRevisionRecord );
	}
}
