<?php

namespace AutoModerator\Tests;

use AutoModerator\RevisionCheck;
use AutoModerator\Services\AutoModeratorRollback;
use ChangeTags;
use DummyContentForTesting;
use MediaWiki\Block\AbstractBlock;
use MediaWiki\Config\Config;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Language\Language;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Permissions\RestrictionStore;
use MediaWiki\Revision\MutableRevisionRecord;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Revision\RevisionStoreRecord;
use MediaWiki\Tests\Unit\MockServiceDependenciesTrait;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;
use MediaWikiUnitTestCase;
use MockHttpTrait;
use MockTitleTrait;
use PHPUnit\Framework\MockObject\MockObject;
use StatusValue;
use WikiPage;

/**
 * @group AutoModerator
 * @group extensions
 * @coversDefaultClass \AutoModerator\RevisionCheck
 */
class RevisionCheckTest extends MediaWikiUnitTestCase {
	use MockHttpTrait;
	use MockServiceDependenciesTrait;
	use MockTitleTrait;

	private Title $title;
	private Language $lang;
	private UserIdentity $user;
	private User $selfUser;
	private User $anonUser;
	private WikiPage $wikiPageMock;
	private array $fakeRevisions;
	private RevisionRecord $rev;
	private array $failingScore;
	private array $passingScore;
	private User $autoModeratorUser;
	private array $tags;
	private ContentHandler $contentHandler;
	private \Psr\Log\LoggerInterface $logger;
	private RestrictionStore $restrictionStore;
	private WikiPageFactory $wikiPageFactory;
	private RevisionStore $revisionStoreMock;
	private Config $config;
	private Config $wikiConfig;
	private string $undoSummary;
	private PermissionManager $permissionManager;
	private AutoModeratorRollback $rollbackPage;

	/**
	 * cribbed from MediaWiki\Tests\Rest\Handler\UserContributionsHandlerTest
	 *
	 * @return MutableRevisionRecord[]
	 */
	private function makeFakeRevisions( int $numRevs, int $limit, int $segment = 1 ) {
		$mockRevisionRecord = $this->createMock( RevisionStoreRecord::class );
		$revisions = [];
		for ( $i = $numRevs; $i >= 1; $i-- ) {
			$rev = $this->createMock( RevisionRecord::class );
			$rev->method( 'getId' )->willReturn( $i );
			$rev->method( 'getContent' )->willReturn( new DummyContentForTesting( 'Lorem Ipsum' ) );
			$rev->method( 'getUser' )->willReturn( $this->user );
			$revisions[] = $rev;
		}
		return array_slice( $revisions, $segment - 1, $limit );
	}

	/**
	 * @param int $ns
	 * @return WikiPage|MockObject
	 */
	private function getMockPageAndRollbackPage( int $ns, bool $isOk = true, array $errorMessages = [] ): array {
		$ret = $this->createMock( WikiPage::class );
		$ret->method( 'getNamespace' )->willReturn( $ns );
		$ret->method( 'canExist' )->willReturn( true );
		$ret->method( 'exists' )->willReturn( true );
		$ret->method( 'getId' )->willReturn( 1 );
		$title = $this->title;
		$title->method( 'getPrefixedText' )->willReturn( 'Foo' );
		$title->method( 'getText' )->willReturn( 'Foo' );
		$title->method( 'getDBkey' )->willReturn( 'Foo' );
		$title->method( 'getPageLanguage' )->willReturn( $this->createMock( Language::class ) );
		$ret->method( 'getTitle' )->willReturn( $title );
		$mockStatus = $this->createMock( StatusValue::class );
		$mockStatus->method( 'isOK' )->willReturn( $isOk );
		$mockStatus->method( 'getMessages' )->willReturn( $errorMessages );
		$rollbackPage = $this->createMock( AutoModeratorRollback::class );
		$rollbackPage->method( 'setSummary' )->willReturn( $rollbackPage );
		$rollbackPage->method( "rollback" )->willReturn( $mockStatus );
		return [ $ret, $rollbackPage ];
	}

	/**
	 * @param MutableRevisionRecord[] $fakeRevisions
	 * @param RevisionStoreRecord $rev
	 * @return RevisionStore|MockObject
	 */
	private function getMockRevisionStore( $fakeRevisions, $rev ): RevisionStore {
		$ret = $this->createMock( RevisionStore::class );
		end( $fakeRevisions );
		prev( $fakeRevisions );
		$ret->method( 'getPreviousRevision' )->willReturn( $rev );
		$ret->method( 'getRevisionById' )->willReturn( $rev );
		return $ret;
	}

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock( Config::class );
		$this->config->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorUsername', 'AutoModerator' ],
			[ 'DisableAnonTalk', false ],
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
		$this->title = $this->makeMockTitle( 'Main_Page', [ 'id' => 1 ] );
		$this->lang = $this->createMock( Language::class );
		$this->user = $this->createMock( User::class );
		$this->user->method( 'getName' )->willReturn( 'ATestUser' );
		$this->user->method( 'isRegistered' )->willReturn( true );
		$this->user->method( 'equals' )->willReturn( false );
		$this->selfUser = $this->createMock( User::class );
		$this->selfUser->method( 'getName' )->willReturn( 'ATestUserSelf' );
		$this->selfUser->method( 'isRegistered' )->willReturn( true );
		$this->selfUser->method( 'equals' )->willReturn( true );
		$this->anonUser = $this->createMock( User::class );
		$this->anonUser->method( 'getName' )->willReturn( '127.0.0.1' );
		$this->anonUser->method( 'isRegistered' )->willReturn( false );
		$this->wikiPageMock = $this->getMockPageAndRollbackPage( NS_MAIN )[0];
		$this->rollbackPage = $this->getMockPageAndRollbackPage( NS_MAIN )[1];
		$this->fakeRevisions = $this->makeFakeRevisions( 3, 3 );
		$this->rev = current( $this->fakeRevisions );
		$this->rev->method( 'getParentId' )->willReturn( 1 );
		$this->wikiPageMock->method( 'getRevisionRecord' )->willReturn( $this->fakeRevisions[ 2 ] );
		$this->failingScore = [
			'model_name' => 'revertrisk-language-agnostic',
			'model_version' => '3',
			'wiki_db' => 'enwiki',
			'revision_id' => end( $this->fakeRevisions )->getId(),
			'output' => [
				'prediction' => true,
				'probabilities' => [
					'true' => 1.000000000000000,
					'false' => 0.000000000000000,
				],
			],
		];
		$this->passingScore = [
			'model_name' => 'revertrisk-language-agnostic',
			'model_version' => '3',
			'wiki_db' => 'enwiki',
			'revision_id' => end( $this->fakeRevisions )->getId(),
			'output' => [
				'prediction' => false,
				'probabilities' => [
					'true' => 0.000000000000000,
					'false' => 1.000000000000000,
				],
			],
		];
		$this->autoModeratorUser = $this->createMock( User::class );
		$this->tags = [];
		$this->revisionStoreMock = $this->getMockRevisionStore( $this->fakeRevisions, $this->rev );
		$this->revisionStoreMock->method( 'getPreviousRevision' )->willReturn( $this->fakeRevisions[ 1 ] );
		$this->revisionStoreMock->method( 'getFirstRevision' )->willReturn( $this->fakeRevisions[ 0 ] );

		$this->wikiConfig = $this->createMock( Config::class );
		$this->wikiConfig->method( 'get' )->willReturnMap( [
				[
					'AutoModeratorUndoSummary',
					'[[Special:Diff/$1|$1]] by [[Special:Contributions/$2|$2]] ([[User talk:$2|talk]]'
				],
				[ 'AutoModeratorUndoSummaryAnon', '[[Special:Diff/$1|$1]] by [[Special:Contributions/$2|$2]]' ],
				[ 'AutoModeratorSkipUserRights', [ 'bot', 'autopatrol' ] ],
				[ 'AutoModeratorUseEditFlagMinor', false ],
				[ 'AutoModeratorCautionLevel', 'very-cautious' ]
		] );
		$contentHandler = $this->createMock( ContentHandler::class );
		$this->contentHandler = new $contentHandler( CONTENT_MODEL_TEXT, 'text/plain' );
		$this->contentHandler->method( 'getUndoContent' )->willReturn( new DummyContentForTesting( 'Lorem Ipsum' ) );
		$this->logger = $this->createMock( \Psr\Log\LoggerInterface::class );
		$this->restrictionStore = $this->createMock( RestrictionStore::class );
		$this->wikiPageFactory = $this->createMock( WikiPageFactory::class );
		$this->wikiPageFactory->method( 'newFromID' )->willReturn( $this->wikiPageMock );
		$this->undoSummary = "undoSummary";
		$this->permissionManager = $this->createMock( PermissionManager::class );
	}

	/**
	 * @covers ::maybeRollback
	 */
	public function testMaybeRollbackBadEdit() {
		$revisionCheck = new RevisionCheck(
			$this->wikiPageMock->getId(),
			$this->wikiPageFactory,
			$this->rev->getId(),
			$this->autoModeratorUser,
			$this->revisionStoreMock,
			$this->wikiConfig,
			$this->config,
			$this->contentHandler,
			$this->undoSummary,
			$this->rollbackPage,
			true
		);
		$reverted = array_key_first( $revisionCheck->maybeRollback(
			$this->failingScore,
			'revertrisklanguageagnostic'
		) );
		$this->assertSame( 1, $reverted );
	}

	/**
	 * @covers ::maybeRollback
	 */
	public function testMaybeRollbackNoContent() {
		$contentHandler = $this->createMock( ContentHandler::class );
		$this->contentHandler = new $contentHandler( CONTENT_MODEL_TEXT, 'text/plain' );
		$this->contentHandler->method( 'getUndoContent' )->willReturn( false );
		$revisionCheck = new RevisionCheck(
			$this->wikiPageMock->getId(),
			$this->wikiPageFactory,
			$this->rev->getId(),
			$this->autoModeratorUser,
			$this->revisionStoreMock,
			$this->wikiConfig,
			$this->config,
			$this->contentHandler,
			$this->undoSummary,
			$this->rollbackPage,
			true
		);
		$reverted = $revisionCheck->maybeRollback(
			$this->failingScore,
			'revertrisklanguageagnostic'
		);
		$this->assertSame( 0, array_key_first( $reverted ) );
		$this->assertSame( "failure", $reverted[0] );
	}

	/**
	 * @covers ::maybeRollback
	 */
	public function testMaybeRollbackBadSaveStatus() {
		$wikiPageFactory = $this->createMock( WikiPageFactory::class );
		$wikiTestPages = $this->getMockPageAndRollbackPage( NS_MAIN,
			false,
			[ $this->getMockMessage( "Generic Error Message" ) ] );
		$wikiPage  = $wikiTestPages[0];
		$wikiPage->method( 'getRevisionRecord' )->willReturn( $this->fakeRevisions[ 2 ] );
		$wikiPageFactory->method( 'newFromID' )->willReturn( $wikiPage );
		$rollbackPage = $wikiTestPages[1];
		$revisionCheck = new RevisionCheck(
			$wikiPage->getId(),
			$wikiPageFactory,
			$this->rev->getId(),
			$this->autoModeratorUser,
			$this->revisionStoreMock,
			$this->wikiConfig,
			$this->config,
			$this->contentHandler,
			$this->undoSummary,
			$rollbackPage,
			true
		);
		$reverted = $revisionCheck->maybeRollback(
			$this->failingScore,
			'revertrisklanguageagnostic'
		);
		$this->assertSame( 0, array_key_first( $reverted ) );
		$this->assertSame( "Generic Error Message", $reverted[0] );
	}

	/**
	 * @covers ::maybeRollback
	 */
	public function testMaybeRollbackBadSaveStatusEditConflict() {
		$wikiPageFactory = $this->createMock( WikiPageFactory::class );
		$wikiTestPages = $this->getMockPageAndRollbackPage( NS_MAIN,
			false,
			[ $this->getMockMessage( "edit-conflict" ) ] );
		$wikiPage  = $wikiTestPages[0];
		$wikiPage->method( 'getRevisionRecord' )->willReturn( $this->fakeRevisions[ 2 ] );
		$wikiPageFactory->method( 'newFromID' )->willReturn( $wikiPage );
		$rollbackPage = $wikiTestPages[1];
		$revisionCheck = new RevisionCheck(
			$wikiPage->getId(),
			$wikiPageFactory,
			$this->rev->getId(),
			$this->autoModeratorUser,
			$this->revisionStoreMock,
			$this->wikiConfig,
			$this->config,
			$this->contentHandler,
			$this->undoSummary,
			$rollbackPage,
			true
		);
		$reverted = $revisionCheck->maybeRollback(
			$this->failingScore,
			'revertrisklanguageagnostic'
		);
		$this->assertSame( 0, array_key_first( $reverted ) );
		$this->assertSame( "success", $reverted[0] );
	}

	/**
	 * @covers ::maybeRollback
	 */
	public function testMaybeRollbackBadSaveStatusAlreadyRolled() {
		$wikiPageFactory = $this->createMock( WikiPageFactory::class );
		$wikiTestPages = $this->getMockPageAndRollbackPage( NS_MAIN,
			false,
			[ $this->getMockMessage( "alreadyrolled" ) ] );
		$wikiPage  = $wikiTestPages[0];
		$wikiPage->method( 'getRevisionRecord' )->willReturn( $this->fakeRevisions[ 2 ] );
		$wikiPageFactory->method( 'newFromID' )->willReturn( $wikiPage );
		$rollbackPage = $wikiTestPages[1];
		$revisionCheck = new RevisionCheck(
			$wikiPage->getId(),
			$wikiPageFactory,
			$this->rev->getId(),
			$this->autoModeratorUser,
			$this->revisionStoreMock,
			$this->wikiConfig,
			$this->config,
			$this->contentHandler,
			$this->undoSummary,
			$rollbackPage,
			true
		);
		$reverted = $revisionCheck->maybeRollback(
			$this->failingScore,
			'revertrisklanguageagnostic'
		);
		$this->assertSame( 0, array_key_first( $reverted ) );
		$this->assertSame( "success", $reverted[0] );
	}

	/**
	 * @covers ::maybeRollback
	 */
	public function testMaybeRollbackBadSaveStatusNoMessage() {
		$wikiPageFactory = $this->createMock( WikiPageFactory::class );
		$wikiTestPages = $this->getMockPageAndRollbackPage( NS_MAIN,
			false );
		$wikiPage  = $wikiTestPages[0];
		$rollbackPage = $wikiTestPages[1];
		$wikiPage->method( 'getRevisionRecord' )->willReturn( $this->fakeRevisions[ 2 ] );
		$wikiPageFactory->method( 'newFromID' )->willReturn( $wikiPage );
		$revisionCheck = new RevisionCheck(
			$wikiPage->getId(),
			$wikiPageFactory,
			$this->rev->getId(),
			$this->autoModeratorUser,
			$this->revisionStoreMock,
			$this->wikiConfig,
			$this->config,
			$this->contentHandler,
			$this->undoSummary,
			$rollbackPage,
			true
		);
		$reverted = $revisionCheck->maybeRollback(
			$this->failingScore,
			'revertrisklanguageagnostic'
		);
		$this->assertSame( 0, array_key_first( $reverted ) );
		$this->assertSame( "Failed to save revision", $reverted[0] );
	}

	/**
	 * @covers ::maybeRollback
	 */
	public function testMaybeRollbackGoodEdit() {
		$revisionCheck = new RevisionCheck(
			$this->wikiPageMock->getId(),
			$this->wikiPageFactory,
			$this->rev->getId(),
			$this->autoModeratorUser,
			$this->revisionStoreMock,
			$this->wikiConfig,
			$this->config,
			$this->contentHandler,
			$this->undoSummary,
			$this->rollbackPage
		);
		$reverted = $revisionCheck->maybeRollback(
			$this->passingScore,
			'revertrisklanguageagnostic'
		);
		$this->assertSame( 0, array_key_first( $reverted ) );
		$this->assertSame( 'Not reverted', $reverted[0] );
	}

	/**
	 * @covers ::maybeRollback with lower than minimum threshold configured and passing score
	 */
	public function testMaybeRollbackWithLowThresholdSuccess() {
		$config = $this->createMock( Config::class );
		$config->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorRevertProbability', 0 ],
			[ 'AutoModeratorUsername', 'AutoModerator' ],
			[ 'DisableAnonTalk', false ]
		] );

		$revisionCheck = new RevisionCheck(
			$this->wikiPageMock->getId(),
			$this->wikiPageFactory,
			$this->rev->getId(),
			$this->autoModeratorUser,
			$this->revisionStoreMock,
			$this->wikiConfig,
			$this->config,
			$this->contentHandler,
			$this->undoSummary,
			$this->rollbackPage
		);

		$reverted = array_key_first( $revisionCheck->maybeRollback(
			$this->passingScore,
			'revertrisklanguageagnostic'
		) );
		$this->assertSame( 0, $reverted );
	}

	/**
	 * @covers ::maybeRollback with lower than minimum threshold configured and failing score
	 */
	public function testMaybeRollbackWithLowThresholdFailing() {
		$config = $this->createMock( Config::class );
		$config->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorRevertProbability', 0 ],
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
			[ 'DisableAnonTalk', false ]
		] );

		$revisionCheck = new RevisionCheck(
			$this->wikiPageMock->getId(),
			$this->wikiPageFactory,
			$this->rev->getId(),
			$this->autoModeratorUser,
			$this->revisionStoreMock,
			$this->wikiConfig,
			$this->config,
			$this->contentHandler,
			$this->undoSummary,
			$this->rollbackPage
		);

		$reverted = array_key_first( $revisionCheck->maybeRollback(
			$this->failingScore,
			'revertrisklanguageagnostic'
		) );
		$this->assertSame( 1, $reverted );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckAutoModeratorEdit() {
		$passedPreCheck = RevisionCheck::revertPreCheck(
			$this->selfUser,
			$this->autoModeratorUser,
			$this->logger,
			$this->revisionStoreMock,
			$this->tags,
			$this->restrictionStore,
			$this->wikiPageFactory,
			$this->wikiConfig,
			$this->rev,
			$this->permissionManager
		);
		$this->assertFalse( $passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testSelfRevertPreCheckTagRevertEdit() {
		$this->tags = [ ChangeTags::TAG_MANUAL_REVERT ];
		$passedPreCheck = RevisionCheck::revertPreCheck(
			$this->selfUser,
			$this->autoModeratorUser,
			$this->logger,
			$this->revisionStoreMock,
			$this->tags,
			$this->restrictionStore,
			$this->wikiPageFactory,
			$this->wikiConfig,
			$this->rev,
			$this->permissionManager
		);
		$this->assertFalse( $passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckAutoModeratorBlocked() {
		$block = $this->createMock( AbstractBlock::class );
		$block->method( 'appliesToPage' )->willReturn( true );
		$this->autoModeratorUser->method( 'getBlock' )->willReturn( $block );
		$this->assertFalse( RevisionCheck::revertPreCheck(
			$this->user,
			$this->autoModeratorUser,
			$this->logger,
			$this->revisionStoreMock,
			$this->tags,
			$this->restrictionStore,
			$this->wikiPageFactory,
			$this->wikiConfig,
			$this->rev,
			$this->permissionManager
		) );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckAutoModeratorBlockedButNotOnPage() {
		$block = $this->createMock( AbstractBlock::class );
		$block->method( 'appliesToPage' )->willReturn( false );
		$this->autoModeratorUser->method( 'getBlock' )->willReturn( $block );
		$this->assertTrue( RevisionCheck::revertPreCheck(
			$this->user,
			$this->autoModeratorUser,
			$this->logger,
			$this->revisionStoreMock,
			$this->tags,
			$this->restrictionStore,
			$this->wikiPageFactory,
			$this->wikiConfig,
			$this->rev,
			$this->permissionManager
		) );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckAutoModeratorNotBlocked() {
		$this->autoModeratorUser->method( 'getBlock' )->willReturn( null );
		$this->assertTrue( RevisionCheck::revertPreCheck(
			$this->user,
			$this->autoModeratorUser,
			$this->logger,
			$this->revisionStoreMock,
			$this->tags,
			$this->restrictionStore,
			$this->wikiPageFactory,
			$this->wikiConfig,
			$this->rev,
			$this->permissionManager
		) );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testOthersRevertPreCheckTagRevertEdit() {
		$this->tags = [ ChangeTags::TAG_MANUAL_REVERT ];
		$passedPreCheck = RevisionCheck::revertPreCheck(
			$this->user,
			$this->autoModeratorUser,
			$this->logger,
			$this->revisionStoreMock,
			$this->tags,
			$this->restrictionStore,
			$this->wikiPageFactory,
			$this->wikiConfig,
			$this->rev,
			$this->permissionManager
		);
		$this->assertTrue( $passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testSelfRevertPreCheckTagRollbackEdit() {
		$this->tags = [ ChangeTags::TAG_ROLLBACK ];
		$passedPreCheck = RevisionCheck::revertPreCheck(
			$this->selfUser,
			$this->autoModeratorUser,
			$this->logger,
			$this->revisionStoreMock,
			$this->tags,
			$this->restrictionStore,
			$this->wikiPageFactory,
			$this->wikiConfig,
			$this->rev,
			$this->permissionManager
		);
		$this->assertFalse( $passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testOthersRevertPreCheckTagRollbackEdit() {
		$this->tags = [ ChangeTags::TAG_ROLLBACK ];
		$passedPreCheck = RevisionCheck::revertPreCheck(
			$this->user,
			$this->autoModeratorUser,
			$this->logger,
			$this->revisionStoreMock,
			$this->tags,
			$this->restrictionStore,
			$this->wikiPageFactory,
			$this->wikiConfig,
			$this->rev,
			$this->permissionManager
		);
		$this->assertTrue( $passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testSelfRevertPreCheckTagUndoEdit() {
		$this->tags = [ ChangeTags::TAG_ROLLBACK ];
		$passedPreCheck = RevisionCheck::revertPreCheck(
			$this->selfUser,
			$this->autoModeratorUser,
			$this->logger,
			$this->revisionStoreMock,
			$this->tags,
			$this->restrictionStore,
			$this->wikiPageFactory,
			$this->wikiConfig,
			$this->rev,
			$this->permissionManager
		);
		$this->assertFalse( $passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testOthersRevertPreCheckTagUndoEdit() {
		$this->tags = [ ChangeTags::TAG_ROLLBACK ];
		$passedPreCheck = RevisionCheck::revertPreCheck(
			$this->user,
			$this->autoModeratorUser,
			$this->logger,
			$this->revisionStoreMock,
			$this->tags,
			$this->restrictionStore,
			$this->wikiPageFactory,
			$this->wikiConfig,
			$this->rev,
			$this->permissionManager
		);
		$this->assertTrue( $passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckTagNewRedirect() {
		$this->tags = [ ChangeTags::TAG_NEW_REDIRECT ];
		$passedPreCheck = RevisionCheck::revertPreCheck(
			$this->user,
			$this->autoModeratorUser,
			$this->logger,
			$this->revisionStoreMock,
			$this->tags,
			$this->restrictionStore,
			$this->wikiPageFactory,
			$this->wikiConfig,
			$this->rev,
			$this->permissionManager
		);
		$this->assertFalse( $passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckTagRemovedRedirect() {
		$this->tags = [ ChangeTags::TAG_REMOVED_REDIRECT ];
		$passedPreCheck = RevisionCheck::revertPreCheck(
			$this->user,
			$this->autoModeratorUser,
			$this->logger,
			$this->revisionStoreMock,
			$this->tags,
			$this->restrictionStore,
			$this->wikiPageFactory,
			$this->wikiConfig,
			$this->rev,
			$this->permissionManager
		);
		$this->assertFalse( $passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckTagChangedRedirect() {
		$this->tags = [ ChangeTags::TAG_CHANGED_REDIRECT_TARGET ];
		$passedPreCheck = RevisionCheck::revertPreCheck(
			$this->user,
			$this->autoModeratorUser,
			$this->logger,
			$this->revisionStoreMock,
			$this->tags,
			$this->restrictionStore,
			$this->wikiPageFactory,
			$this->wikiConfig,
			$this->rev,
			$this->permissionManager
		);
		$this->assertFalse( $passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckSysOp() {
		$this->permissionManager->method( 'userHasAnyRight' )->willReturn( true );
		$passedPreCheck = RevisionCheck::revertPreCheck(
			$this->user,
			$this->autoModeratorUser,
			$this->logger,
			$this->revisionStoreMock,
			$this->tags,
			$this->restrictionStore,
			$this->wikiPageFactory,
			$this->wikiConfig,
			$this->rev,
			$this->permissionManager
		);
		$this->assertFalse( $passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckBot() {
		$this->permissionManager->method( 'userHasAnyRight' )->willReturn( true );
		$passedPreCheck = RevisionCheck::revertPreCheck(
			$this->user,
			$this->autoModeratorUser,
			$this->logger,
			$this->revisionStoreMock,
			$this->tags,
			$this->restrictionStore,
			$this->wikiPageFactory,
			$this->wikiConfig,
			$this->rev,
			$this->permissionManager
		);
		$this->assertFalse( $passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckFalseWhenExternalUser() {
		$this->user = $this->createMock( User::class );
		$this->user->method( 'getName' )->willReturn( 'ATestUser>' );
		$this->user->method( 'isRegistered' )->willReturn( true );
		$this->user->method( 'equals' )->willReturn( false );
		$passedPreCheck = RevisionCheck::revertPreCheck(
			$this->user,
			$this->autoModeratorUser,
			$this->logger,
			$this->revisionStoreMock,
			$this->tags,
			$this->restrictionStore,
			$this->wikiPageFactory,
			$this->wikiConfig,
			$this->rev,
			$this->permissionManager
		);
		$this->assertFalse( $passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckFalseWhenSelfRevert() {
		$this->user = $this->createMock( User::class );
		$this->user->method( 'getName' )->willReturn( 'ATestUser' );
		$this->user->method( 'isRegistered' )->willReturn( true );
		$this->user->method( 'equals' )->willReturn( true );
		$this->tags = [ ChangeTags::TAG_MANUAL_REVERT ];
		$this->revisionStoreMock = $this->createMock( RevisionStore::class );

		$mockParentRevision = $this->createMock( RevisionRecord::class );
		$mockParentRevision->method( 'getUser' )->willReturn( $this->user );
		$mockParentRevision->method( "getId" )->willReturn( 1 );

		$mockRevision = $this->createMock( RevisionRecord::class );
		$mockRevision->method( "getParentId" )->willReturn( 1 );
		$mockRevision->method( 'getUser' )->willReturn( $this->user );
		$mockRevision->method( "getId" )->willReturn( 2 );

		$this->revisionStoreMock->method( 'getRevisionById' )->willReturn( $mockRevision );

		$passedPreCheck = RevisionCheck::revertPreCheck(
			$this->user,
			$this->autoModeratorUser,
			$this->logger,
			$this->revisionStoreMock,
			$this->tags,
			$this->restrictionStore,
			$this->wikiPageFactory,
			$this->wikiConfig,
			$mockRevision,
			$this->permissionManager
		);
		$this->assertFalse( $passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckTrueWhenNotSelfRevert() {
		$this->user = $this->createMock( User::class );
		$this->user->method( 'getName' )->willReturn( 'ATestUser' );
		$this->user->method( 'isRegistered' )->willReturn( true );
		$this->user->method( 'equals' )->willReturn( false );
		$this->tags = [ ChangeTags::TAG_MANUAL_REVERT ];
		$this->revisionStoreMock = $this->createMock( RevisionStore::class );

		$mockParentRevision = $this->createMock( RevisionRecord::class );
		$mockParentRevision->method( 'getUser' )->willReturn( $this->user );
		$mockParentRevision->method( "getId" )->willReturn( 1 );

		$mockRevision = $this->createMock( RevisionRecord::class );
		$mockRevision->method( "getParentId" )->willReturn( 1 );
		$mockRevision->method( 'getUser' )->willReturn( $this->user );
		$mockRevision->method( "getId" )->willReturn( 2 );

		$this->revisionStoreMock->method( 'getRevisionById' )->willReturn( $mockRevision );

		$passedPreCheck = RevisionCheck::revertPreCheck(
			$this->user,
			$this->autoModeratorUser,
			$this->logger,
			$this->revisionStoreMock,
			$this->tags,
			$this->restrictionStore,
			$this->wikiPageFactory,
			$this->wikiConfig,
			$mockRevision,
			$this->permissionManager
		);
		$this->assertTrue( $passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckFalseWhenAutoModeratorIsParentRev() {
		$this->autoModeratorUser = $this->createMock( User::class );
		$this->autoModeratorUser->method( 'equals' )->willReturn( true );

		$this->tags = [ ChangeTags::TAG_MANUAL_REVERT ];
		$this->revisionStoreMock = $this->createMock( RevisionStore::class );

		$mockParentRevision = $this->createMock( RevisionRecord::class );
		$mockParentRevision->method( 'getUser' )->willReturn( $this->autoModeratorUser );
		$mockParentRevision->method( "getId" )->willReturn( 1 );

		$mockRevision = $this->createMock( RevisionRecord::class );
		$mockRevision->method( "getParentId" )->willReturn( 1 );
		$mockRevision->method( 'getUser' )->willReturn( $this->autoModeratorUser );
		$mockRevision->method( "getId" )->willReturn( 2 );

		$this->revisionStoreMock->method( 'getRevisionById' )->willReturn( $mockRevision );

		$passedPreCheck = RevisionCheck::revertPreCheck(
			$this->user,
			$this->autoModeratorUser,
			$this->logger,
			$this->revisionStoreMock,
			$this->tags,
			$this->restrictionStore,
			$this->wikiPageFactory,
			$this->wikiConfig,
			$mockRevision,
			$this->permissionManager
		);
		$this->assertFalse( $passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckTrueWhenAutoModeratorIsNotParentRev() {
		$this->autoModeratorUser = $this->createMock( User::class );
		$this->autoModeratorUser->method( 'equals' )->willReturn( false );

		$this->tags = [ ChangeTags::TAG_MANUAL_REVERT ];
		$this->revisionStoreMock = $this->createMock( RevisionStore::class );

		$mockParentRevision = $this->createMock( RevisionRecord::class );
		$mockParentRevision->method( 'getUser' )->willReturn( $this->autoModeratorUser );
		$mockParentRevision->method( "getId" )->willReturn( 1 );

		$mockRevision = $this->createMock( RevisionRecord::class );
		$mockRevision->method( "getParentId" )->willReturn( 1 );
		$mockRevision->method( 'getUser' )->willReturn( $this->autoModeratorUser );
		$mockRevision->method( "getId" )->willReturn( 2 );

		$this->revisionStoreMock->method( 'getRevisionById' )->willReturn( $mockRevision );

		$passedPreCheck = RevisionCheck::revertPreCheck(
			$this->user,
			$this->autoModeratorUser,
			$this->logger,
			$this->revisionStoreMock,
			$this->tags,
			$this->restrictionStore,
			$this->wikiPageFactory,
			$this->wikiConfig,
			$mockRevision,
			$this->permissionManager
		);
		$this->assertTrue( $passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckFalseWhenNoParentRevision() {
		$this->tags = [ ChangeTags::TAG_MANUAL_REVERT ];
		$this->revisionStoreMock = $this->createMock( RevisionStore::class );
		$mockRevision = $this->createMock( RevisionRecord::class );
		$mockRevision->method( "getParentId" )->willReturn( 1 );
		$mockRevision->method( 'getUser' )->willReturn( $this->autoModeratorUser );
		$mockRevision->method( "getId" )->willReturn( 2 );

		$this->revisionStoreMock
			->method( 'getRevisionById' )
			->with( 1 )
			->willReturn( null );

		$passedPreCheck = RevisionCheck::revertPreCheck(
			$this->user,
			$this->autoModeratorUser,
			$this->logger,
			$this->revisionStoreMock,
			$this->tags,
			$this->restrictionStore,
			$this->wikiPageFactory,
			$this->wikiConfig,
			$mockRevision,
			$this->permissionManager
		);
		$this->assertFalse( $passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckTrueWhenParentRevision() {
		$this->tags = [ ChangeTags::TAG_MANUAL_REVERT ];
		$this->revisionStoreMock = $this->createMock( RevisionStore::class );
		$mockRevision = $this->createMock( RevisionRecord::class );
		$mockRevision->method( "getParentId" )->willReturn( 1 );
		$mockRevision->method( 'getUser' )->willReturn( $this->user );
		$mockRevision->method( "getId" )->willReturn( 2 );

		$this->revisionStoreMock
			->method( 'getRevisionById' )
			->with( 1 )
			->willReturn( $this->fakeRevisions[0] );

		$passedPreCheck = RevisionCheck::revertPreCheck(
			$this->user,
			$this->autoModeratorUser,
			$this->logger,
			$this->revisionStoreMock,
			$this->tags,
			$this->restrictionStore,
			$this->wikiPageFactory,
			$this->wikiConfig,
			$mockRevision,
			$this->permissionManager
		);
		$this->assertTrue( $passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckFalseWhenNoParentRevisionUser() {
		$this->tags = [ ChangeTags::TAG_MANUAL_REVERT ];
		$this->revisionStoreMock = $this->createMock( RevisionStore::class );
		$mockRevision = $this->createMock( RevisionRecord::class );
		$mockRevision->method( "getParentId" )->willReturn( $this->fakeRevisions[0]->getId() );
		$mockRevision->method( 'getUser' )->willReturn( null );
		$mockRevision->method( "getId" )->willReturn( 2 );

		$this->revisionStoreMock->method( 'getRevisionById' )->willReturn( $mockRevision );

		$passedPreCheck = RevisionCheck::revertPreCheck(
			$this->user,
			$this->autoModeratorUser,
			$this->logger,
			$this->revisionStoreMock,
			$this->tags,
			$this->restrictionStore,
			$this->wikiPageFactory,
			$this->wikiConfig,
			$mockRevision,
			$this->permissionManager
		);
		$this->assertFalse( $passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckTrueWhenParentRevisionUser() {
		$this->autoModeratorUser = $this->createMock( User::class );
		$this->autoModeratorUser->method( 'equals' )->willReturn( true );

		$this->tags = [ ChangeTags::TAG_MANUAL_REVERT ];
		$this->revisionStoreMock = $this->createMock( RevisionStore::class );

		$mockRevision = $this->createMock( RevisionRecord::class );
		$mockRevision->method( "getParentId" )->willReturn( 1 );
		$mockRevision->method( 'getUser' )->willReturn( $this->user );
		$mockRevision->method( "getId" )->willReturn( 2 );

		$this->revisionStoreMock->method( 'getRevisionById' )->willReturn( $mockRevision );

		$passedPreCheck = RevisionCheck::revertPreCheck(
			$this->user,
			$this->autoModeratorUser,
			$this->logger,
			$this->revisionStoreMock,
			$this->tags,
			$this->restrictionStore,
			$this->wikiPageFactory,
			$this->wikiConfig,
			$mockRevision,
			$this->permissionManager
		);
		$this->assertTrue( $passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckMainSpaceEdit() {
		$passedPreCheck = RevisionCheck::revertPreCheck(
			$this->user,
			$this->autoModeratorUser,
			$this->logger,
			$this->revisionStoreMock,
			$this->tags,
			$this->restrictionStore,
			$this->wikiPageFactory,
			$this->wikiConfig,
			$this->rev,
			$this->permissionManager
		);
		$this->assertTrue( $passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckNonMainSpaceEdit() {
		$wikiPageMock = $this->getMockPageAndRollbackPage( NS_TALK )[0];
		$wikiPageFactory = $this->createMock( WikiPageFactory::class );
		$wikiPageFactory->method( 'newFromID' )->willReturn( $wikiPageMock );
		$passedPreCheck = RevisionCheck::revertPreCheck(
			$this->user,
			$this->autoModeratorUser,
			$this->logger,
			$this->revisionStoreMock,
			$this->tags,
			$this->restrictionStore,
			$wikiPageFactory,
			$this->wikiConfig,
			$this->rev,
			$this->permissionManager
		);
		$this->assertFalse( $passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckNewPage() {
		// Override revisionStoreMock method
		$fakeRevisions = $this->makeFakeRevisions( 3, 3 );
		$rev = current( $fakeRevisions );
		$revisionStoreMock = $this->getMockRevisionStore( $fakeRevisions, $rev );
		$passedPreCheck = RevisionCheck::revertPreCheck(
			$this->user,
			$this->autoModeratorUser,
			$this->logger,
			$revisionStoreMock,
			$this->tags,
			$this->restrictionStore,
			$this->wikiPageFactory,
			$this->wikiConfig,
			$rev,
			$this->permissionManager
		);
		$this->assertFalse( $passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckProtectedPage() {
		// Override revisionStoreMock method
		$this->restrictionStore->method( 'isProtected' )->willReturn( true );
		$passedPreCheck = RevisionCheck::revertPreCheck(
			$this->user,
			$this->autoModeratorUser,
			$this->logger,
			$this->revisionStoreMock,
			$this->tags,
			$this->restrictionStore,
			$this->wikiPageFactory,
			$this->wikiConfig,
			$this->rev,
			$this->permissionManager
		);
		$this->assertFalse( $passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckNullPage() {
		$wikiPageFactory = $this->createMock( WikiPageFactory::class );
		$wikiPageFactory->method( 'newFromID' )->willReturn( null );
		$passedPreCheck = RevisionCheck::revertPreCheck(
			$this->user,
			$this->autoModeratorUser,
			$this->logger,
			$this->revisionStoreMock,
			$this->tags,
			$this->restrictionStore,
			$wikiPageFactory,
			$this->wikiConfig,
			$this->rev,
			$this->permissionManager
		);
		$this->assertFalse( $passedPreCheck );
	}

	/**
	 * @covers ::shouldSkipUser
	 */
	public function testShouldSkipUserTrue() {
		$config = $this->createMock( Config::class );
		$config->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorSkipUserRights', [ 'bot' ] ],
		] );
		$this->permissionManager->method( 'userHasAnyRight' )->willReturn( true );
		$this->assertTrue(
			RevisionCheck::shouldSkipUser( $this->permissionManager, $this->autoModeratorUser, $config )
		);
	}

	/**
	 * @covers ::shouldSkipUser
	 */
	public function testShouldSkipUserFalse() {
		$config = $this->createMock( Config::class );
		$config->method( 'get' )->willReturnMap( [
			[ 'AutoModeratorSkipUserRights', [ 'bot' ] ],
		] );
		$this->permissionManager->method( 'userHasAnyRight' )->willReturn( false );
		$this->assertFalse( RevisionCheck::shouldSkipUser( $this->permissionManager, $this->user, $config ) );
	}

	/**
	 * @covers ::areUsersEqual
	 */
	public function testAreUsersEqual() {
		$this->autoModeratorUser->method( "equals" )->willReturn( true );
		$this->assertTrue( RevisionCheck::areUsersEqual( $this->autoModeratorUser, $this->autoModeratorUser ) );
	}

	/**
	 * @covers ::areUsersEqual
	 */
	public function testAreUsersEqualNotEqual() {
		$this->assertFalse( RevisionCheck::areUsersEqual( $this->user, $this->autoModeratorUser ) );
	}

	/**
	 * @covers ::isProtectedPage
	 */
	public function testIsProtectedPageTrue() {
		$this->restrictionStore->method( 'isProtected' )->willReturn( true );
		$this->restrictionStore->method( 'isSemiProtected' )->willReturn( false );
		$this->assertTrue( RevisionCheck::isProtectedPage( $this->restrictionStore, $this->wikiPageMock ) );
	}

	/**
	 * @covers ::isProtectedPage
	 */
	public function testIsProtectedPageFalse() {
		$this->restrictionStore->method( 'isProtected' )->willReturn( false );
		$this->assertFalse( RevisionCheck::isProtectedPage( $this->restrictionStore, $this->wikiPageMock ) );
	}

	/**
	 * @covers ::isProtectedPage
	 */
	public function testIsProtectedPageFalseWhenSemiProtected() {
		$this->restrictionStore->method( 'isProtected' )->willReturn( true );
		$this->restrictionStore->method( 'isSemiProtected' )->willReturn( true );
		$this->assertFalse( RevisionCheck::isProtectedPage( $this->restrictionStore, $this->wikiPageMock ) );
	}

	/**
	 * @covers ::isNewPageCreation
	 */
	public function testIsNewPageCreationTrueWhenParentIdIsNull() {
		$this->assertTrue( RevisionCheck::isNewPageCreation( null ) );
	}

	/**
	 * @covers ::isNewPageCreation
	 */
	public function testIsNewPageCreationTrueWhenParentIdIsZero() {
		$this->assertTrue( RevisionCheck::isNewPageCreation( 0 ) );
	}

	/**
	 * @covers ::isNewPageCreation
	 */
	public function testIsNewPageCreationFalseWhenSet() {
		$this->assertFalse( RevisionCheck::isNewPageCreation( 2 ) );
	}
}
