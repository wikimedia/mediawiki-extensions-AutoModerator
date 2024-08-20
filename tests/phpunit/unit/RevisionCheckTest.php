<?php

namespace AutoModerator\Tests;

use AutoModerator\RevisionCheck;
use ContentHandler;
use DummyContentForTesting;
use Language;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Config\Config;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Permissions\RestrictionStore;
use MediaWiki\Revision\MutableRevisionRecord;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionSlots;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Revision\RevisionStoreRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Storage\PageUpdater;
use MediaWiki\Tests\Unit\MockServiceDependenciesTrait;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserGroupMembership;
use MediaWikiUnitTestCase;
use MockHttpTrait;
use MockTitleTrait;
use PHPUnit\Framework\MockObject\MockObject;
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
	private User $user;
	private User $selfUser;
	private User $anonUser;
	private WikiPage $wikiPageMock;
	private array $fakeRevisions;
	private RevisionRecord $rev;
	private array $failingScore;
	private array $passingScore;
	private bool $originalRevId;
	private User $autoModeratorUser;
	private array $tags;
	private ContentHandler $contentHandler;
	private \Psr\Log\LoggerInterface $logger;
	private UserGroupManager $userGroupManager;
	private RestrictionStore $restrictionStore;
	private WikiPageFactory $wikiPageFactory;
	private RevisionStore $revisionStoreMock;
	private Config $config;
	private Config $wikiConfig;
	private string $undoSummary;

	/**
	 * cribbed from MediaWiki\Tests\Rest\Handler\UserContributionsHandlerTest
	 *
	 * @return MutableRevisionRecord[]
	 */
	private function makeFakeRevisions( int $numRevs, int $limit, int $segment = 1 ) {
		$mockRevisionRecord = $this->createMock( RevisionStoreRecord::class );
		$revisions = [];
		for ( $i = $numRevs; $i >= 1; $i-- ) {
			$ogTimestamp = '2020010100000';
			$wikiId = $rowOverrides['wikiId'] ?? RevisionRecord::LOCAL;
			$comment = CommentStoreComment::newUnsavedComment( 'Edit ' . $i );
			$main = SlotRecord::newUnsaved( SlotRecord::MAIN, new DummyContentForTesting( 'Lorem Ipsum' ) );
			$aux = SlotRecord::newUnsaved( 'aux', new DummyContentForTesting( 'Frumious Bandersnatch' ) );
			$slots = new RevisionSlots( [ $main, $aux ] );
			$row = [
			'rev_id' => $i,
			'rev_page' => 1,
			'rev_timestamp' => $ogTimestamp . $i,
			'rev_deleted' => 0,
			'rev_minor_edit' => 0,
			'rev_parent_id' => ( $i == 0 ) ? null : $i - 1,
			'rev_len' => $slots->computeSize(),
			'rev_sha1' => $slots->computeSha1(),
			];
			$rev = new $mockRevisionRecord( $this->title, $this->user, $comment, (object)$row, $slots, $wikiId );
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
	private function getMockPage( int $ns ): WikiPage {
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
		$updater = $this->createMock( PageUpdater::class );
		$ret->method( 'newPageUpdater' )->willReturn( $updater );
		return $ret;
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
		$this->wikiPageMock = $this->getMockPage( NS_MAIN );
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
		$this->originalRevId = false;
		$this->autoModeratorUser = $this->createMock( User::class );
		$this->tags = [];
		$this->revisionStoreMock = $this->getMockRevisionStore( $this->fakeRevisions, $this->rev );
		$this->revisionStoreMock->method( 'getPreviousRevision' )->willReturn( $this->fakeRevisions[ 1 ] );
		$this->revisionStoreMock->method( 'getFirstRevision' )->willReturn( $this->fakeRevisions[ 0 ] );
		$this->config = $this->createMock( Config::class );
		$this->config->method( 'get' )->willReturnMap( [
				[ 'AutoModeratorUsername', 'AutoModerator' ],
				[ 'DisableAnonTalk', false ]
		] );
		$this->wikiConfig = $this->createMock( Config::class );
		$this->wikiConfig->method( 'get' )->willReturnMap( [
				[
					'AutoModeratorUndoSummary',
					'[[Special:Diff/$1|$1]] by [[Special:Contributions/$2|$2]] ([[User talk:$2|talk]]'
				],
				[ 'AutoModeratorUndoSummaryAnon', '[[Special:Diff/$1|$1]] by [[Special:Contributions/$2|$2]]' ],
				[ 'AutoModeratorSkipUserGroups', [ 'bot', 'sysop' ] ],
				[ 'AutoModeratorUseEditFlagMinor', false ]
		] );
		$contentHandler = $this->createMock( ContentHandler::class );
		$this->contentHandler = new $contentHandler( CONTENT_MODEL_TEXT, 'text/plain' );
		$this->contentHandler->method( 'getUndoContent' )->willReturn( new DummyContentForTesting( 'Lorem Ipsum' ) );
		$this->logger = $this->createMock( \Psr\Log\LoggerInterface::class );
		$this->userGroupManager = $this->createMock( UserGroupManager::class );
		$this->restrictionStore = $this->createMock( RestrictionStore::class );
		$this->wikiPageFactory = $this->createMock( WikiPageFactory::class );
		$this->wikiPageFactory->method( 'newFromID' )->willReturn( $this->wikiPageMock );
		$this->undoSummary = "undoSummary";
	}

	/**
	 * @covers ::maybeRevert
	 */
	public function testMaybeRevertBadEdit() {
		$revisionCheck = new RevisionCheck(
			$this->wikiPageMock->getId(),
			$this->wikiPageFactory,
			$this->rev->getId(),
			$this->originalRevId,
			$this->user,
			$this->tags,
			$this->autoModeratorUser,
			$this->revisionStoreMock,
			$this->config,
			$this->wikiConfig,
			$this->contentHandler,
			$this->logger,
			$this->userGroupManager,
			$this->restrictionStore,
			$this->lang,
			$this->undoSummary,
			true
		);
		$reverted = array_key_first( $revisionCheck->maybeRevert(
			$this->failingScore
		) );
		$this->assertSame( 1, $reverted );
	}

	/**
	 * @covers ::maybeRevert
	 */
	public function testMaybeRevertGoodEdit() {
		$revisionCheck = new RevisionCheck(
			$this->wikiPageMock->getId(),
			$this->wikiPageFactory,
			$this->rev->getId(),
			$this->originalRevId,
			$this->user,
			$this->tags,
			$this->autoModeratorUser,
			$this->revisionStoreMock,
			$this->config,
			$this->wikiConfig,
			$this->contentHandler,
			$this->logger,
			$this->userGroupManager,
			$this->restrictionStore,
			$this->lang,
			$this->undoSummary
		);
		$reverted = array_key_first( $revisionCheck->maybeRevert(
			$this->passingScore
		) );
		$this->assertSame( 0, $reverted );
	}

	/**
	 * @covers ::maybeRevert with lower than minimum threshold configured and passing score
	 */
	public function testMaybeRevertWithLowThresholdSuccess() {
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
			$this->originalRevId,
			$this->user,
			$this->tags,
			$this->autoModeratorUser,
			$this->revisionStoreMock,
			$this->config,
			$this->wikiConfig,
			$this->contentHandler,
			$this->logger,
			$this->userGroupManager,
			$this->restrictionStore,
			$this->lang,
			$this->undoSummary
		);

		$reverted = array_key_first( $revisionCheck->maybeRevert(
			$this->passingScore
		) );
		$this->assertSame( 0, $reverted );
	}

	/**
	 * @covers ::maybeRevert with lower than minimum threshold configured and failing score
	 */
	public function testMaybeRevertWithLowThresholdFailing() {
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
			$this->originalRevId,
			$this->user,
			$this->tags,
			$this->autoModeratorUser,
			$this->revisionStoreMock,
			$this->config,
			$this->wikiConfig,
			$this->contentHandler,
			$this->logger,
			$this->userGroupManager,
			$this->restrictionStore,
			$this->lang,
			$this->undoSummary
		);

		$reverted = array_key_first( $revisionCheck->maybeRevert(
			$this->failingScore
		) );
		$this->assertSame( 1, $reverted );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckAutoModeratorEdit() {
		$revisionCheck = new RevisionCheck(
			$this->wikiPageMock->getId(),
			$this->wikiPageFactory,
			$this->rev->getId(),
			$this->originalRevId,
			$this->selfUser,
			$this->tags,
			$this->autoModeratorUser,
			$this->revisionStoreMock,
			$this->config,
			$this->wikiConfig,
			$this->contentHandler,
			$this->logger,
			$this->userGroupManager,
			$this->restrictionStore,
			$this->lang,
			$this->undoSummary
		);
		$this->assertFalse( $revisionCheck->passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testSelfRevertPreCheckTagRevertEdit() {
		$this->tags = [ 'mw-manual-revert' ];
		$revisionCheck = new RevisionCheck(
			$this->wikiPageMock->getId(),
			$this->wikiPageFactory,
			$this->rev->getId(),
			$this->originalRevId,
			$this->selfUser,
			$this->tags,
			$this->autoModeratorUser,
			$this->revisionStoreMock,
			$this->config,
			$this->wikiConfig,
			$this->contentHandler,
			$this->logger,
			$this->userGroupManager,
			$this->restrictionStore,
			$this->lang,
			$this->undoSummary
		);
		$this->assertFalse( $revisionCheck->passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testOthersRevertPreCheckTagRevertEdit() {
		$this->tags = [ 'mw-manual-revert' ];
		$revisionCheck = new RevisionCheck(
			$this->wikiPageMock->getId(),
			$this->wikiPageFactory,
			$this->rev->getId(),
			$this->originalRevId,
			$this->user,
			$this->tags,
			$this->autoModeratorUser,
			$this->revisionStoreMock,
			$this->config,
			$this->wikiConfig,
			$this->contentHandler,
			$this->logger,
			$this->userGroupManager,
			$this->restrictionStore,
			$this->lang,
			$this->undoSummary
		);
		$this->assertTrue( $revisionCheck->passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testSelfRevertPreCheckTagRollbackEdit() {
		$this->tags = [ 'mw-rollback' ];
		$revisionCheck = new RevisionCheck(
			$this->wikiPageMock->getId(),
			$this->wikiPageFactory,
			$this->rev->getId(),
			$this->originalRevId,
			$this->selfUser,
			$this->tags,
			$this->autoModeratorUser,
			$this->revisionStoreMock,
			$this->config,
			$this->wikiConfig,
			$this->contentHandler,
			$this->logger,
			$this->userGroupManager,
			$this->restrictionStore,
			$this->lang,
			$this->undoSummary
		);
		$this->assertFalse( $revisionCheck->passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testOthersRevertPreCheckTagRollbackEdit() {
		$this->tags = [ 'mw-rollback' ];
		$revisionCheck = new RevisionCheck(
			$this->wikiPageMock->getId(),
			$this->wikiPageFactory,
			$this->rev->getId(),
			$this->originalRevId,
			$this->user,
			$this->tags,
			$this->autoModeratorUser,
			$this->revisionStoreMock,
			$this->config,
			$this->wikiConfig,
			$this->contentHandler,
			$this->logger,
			$this->userGroupManager,
			$this->restrictionStore,
			$this->lang,
			$this->undoSummary
		);
		$this->assertTrue( $revisionCheck->passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testSelfRevertPreCheckTagUndoEdit() {
		$this->tags = [ 'mw-undo' ];
		$revisionCheck = new RevisionCheck(
			$this->wikiPageMock->getId(),
			$this->wikiPageFactory,
			$this->rev->getId(),
			$this->originalRevId,
			$this->selfUser,
			$this->tags,
			$this->autoModeratorUser,
			$this->revisionStoreMock,
			$this->config,
			$this->wikiConfig,
			$this->contentHandler,
			$this->logger,
			$this->userGroupManager,
			$this->restrictionStore,
			$this->lang,
			$this->undoSummary
		);
		$this->assertFalse( $revisionCheck->passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testOthersRevertPreCheckTagUndoEdit() {
		$this->tags = [ 'mw-undo' ];
		$revisionCheck = new RevisionCheck(
			$this->wikiPageMock->getId(),
			$this->wikiPageFactory,
			$this->rev->getId(),
			$this->originalRevId,
			$this->user,
			$this->tags,
			$this->autoModeratorUser,
			$this->revisionStoreMock,
			$this->config,
			$this->wikiConfig,
			$this->contentHandler,
			$this->logger,
			$this->userGroupManager,
			$this->restrictionStore,
			$this->lang,
			$this->undoSummary
		);
		$this->assertTrue( $revisionCheck->passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckTagNewRedirect() {
		$this->tags = [ 'mw-new-redirect' ];
		$revisionCheck = new RevisionCheck(
			$this->wikiPageMock->getId(),
			$this->wikiPageFactory,
			$this->rev->getId(),
			$this->originalRevId,
			$this->user,
			$this->tags,
			$this->autoModeratorUser,
			$this->revisionStoreMock,
			$this->config,
			$this->wikiConfig,
			$this->contentHandler,
			$this->logger,
			$this->userGroupManager,
			$this->restrictionStore,
			$this->lang,
			$this->undoSummary
		);
		$this->assertFalse( $revisionCheck->passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckTagRemovedRedirect() {
		$this->tags = [ 'mw-removed-redirect' ];
		$revisionCheck = new RevisionCheck(
			$this->wikiPageMock->getId(),
			$this->wikiPageFactory,
			$this->rev->getId(),
			$this->originalRevId,
			$this->user,
			$this->tags,
			$this->autoModeratorUser,
			$this->revisionStoreMock,
			$this->config,
			$this->wikiConfig,
			$this->contentHandler,
			$this->logger,
			$this->userGroupManager,
			$this->restrictionStore,
			$this->lang,
			$this->undoSummary
		);
		$this->assertFalse( $revisionCheck->passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckTagChangedRedirect() {
		$this->tags = [ 'mw-changed-redirect-target' ];
		$revisionCheck = new RevisionCheck(
			$this->wikiPageMock->getId(),
			$this->wikiPageFactory,
			$this->rev->getId(),
			$this->originalRevId,
			$this->user,
			$this->tags,
			$this->autoModeratorUser,
			$this->revisionStoreMock,
			$this->config,
			$this->wikiConfig,
			$this->contentHandler,
			$this->logger,
			$this->userGroupManager,
			$this->restrictionStore,
			$this->lang,
			$this->undoSummary
		);
		$this->assertFalse( $revisionCheck->passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckSysOp() {
		$this->userGroupManager->method( 'getUserGroupMemberships' )
			->willReturn( [ 'sysop' => $this->createMock( UserGroupMembership::class ) ] );
		$revisionCheck = new RevisionCheck(
			$this->wikiPageMock->getId(),
			$this->wikiPageFactory,
			$this->rev->getId(),
			$this->originalRevId,
			$this->user,
			$this->tags,
			$this->autoModeratorUser,
			$this->revisionStoreMock,
			$this->config,
			$this->wikiConfig,
			$this->contentHandler,
			$this->logger,
			$this->userGroupManager,
			$this->restrictionStore,
			$this->lang,
			$this->undoSummary
		);
		$this->assertFalse( $revisionCheck->passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckBot() {
		$this->userGroupManager->method( 'getUserGroupMemberships' )
			->willReturn( [ 'bot' => $this->createMock( UserGroupMembership::class ) ] );
		$revisionCheck = new RevisionCheck(
			$this->wikiPageMock->getId(),
			$this->wikiPageFactory,
			$this->rev->getId(),
			$this->originalRevId,
			$this->user,
			$this->tags,
			$this->autoModeratorUser,
			$this->revisionStoreMock,
			$this->config,
			$this->wikiConfig,
			$this->contentHandler,
			$this->logger,
			$this->userGroupManager,
			$this->restrictionStore,
			$this->lang,
			$this->undoSummary
		);
		$this->assertFalse( $revisionCheck->passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckMainSpaceEdit() {
		$revisionCheck = new RevisionCheck(
			$this->wikiPageMock->getId(),
			$this->wikiPageFactory,
			$this->rev->getId(),
			$this->originalRevId,
			$this->user,
			$this->tags,
			$this->autoModeratorUser,
			$this->revisionStoreMock,
			$this->config,
			$this->wikiConfig,
			$this->contentHandler,
			$this->logger,
			$this->userGroupManager,
			$this->restrictionStore,
			$this->lang,
			$this->undoSummary
		);
		$this->assertTrue( $revisionCheck->passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckNonMainSpaceEdit() {
		$wikiPageMock = $this->getMockPage( NS_TALK );
		$wikiPageFactory = $this->createMock( WikiPageFactory::class );
		$wikiPageFactory->method( 'newFromID' )->willReturn( $wikiPageMock );
		$revisionCheck = new RevisionCheck(
			$wikiPageMock->getId(),
			$wikiPageFactory,
			$this->rev->getId(),
			$this->originalRevId,
			$this->user,
			$this->tags,
			$this->autoModeratorUser,
			$this->revisionStoreMock,
			$this->config,
			$this->wikiConfig,
			$this->contentHandler,
			$this->logger,
			$this->userGroupManager,
			$this->restrictionStore,
			$this->lang,
			$this->undoSummary
		);
		$this->assertFalse( $revisionCheck->passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckNewPage() {
		// Override revisionStoreMock method
		$fakeRevisions = $this->makeFakeRevisions( 3, 3 );
		$rev = current( $fakeRevisions );
		$revisionStoreMock = $this->getMockRevisionStore( $fakeRevisions, $rev );
		$revisionCheck = new RevisionCheck(
			$this->wikiPageMock->getId(),
			$this->wikiPageFactory,
			$rev->getId(),
			$this->originalRevId,
			$this->user,
			$this->tags,
			$this->autoModeratorUser,
			$revisionStoreMock,
			$this->config,
			$this->wikiConfig,
			$this->contentHandler,
			$this->logger,
			$this->userGroupManager,
			$this->restrictionStore,
			$this->lang,
			$this->undoSummary
		);
		$this->assertFalse( $revisionCheck->passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckProtectedPage() {
		// Override revisionStoreMock method
		$this->restrictionStore->method( 'isProtected' )->willReturn( true );
		$revisionCheck = new RevisionCheck(
			$this->wikiPageMock->getId(),
			$this->wikiPageFactory,
			$this->rev->getId(),
			$this->originalRevId,
			$this->user,
			$this->tags,
			$this->autoModeratorUser,
			$this->revisionStoreMock,
			$this->config,
			$this->wikiConfig,
			$this->contentHandler,
			$this->logger,
			$this->userGroupManager,
			$this->restrictionStore,
			$this->lang,
			$this->undoSummary
		);
		$this->assertFalse( $revisionCheck->passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckNullPage() {
		$wikiPageFactory = $this->createMock( WikiPageFactory::class );
		$wikiPageFactory->method( 'newFromID' )->willReturn( null );
		$revisionCheck = new RevisionCheck(
			$this->wikiPageMock->getId(),
			$wikiPageFactory,
			$this->rev->getId(),
			$this->originalRevId,
			$this->user,
			$this->tags,
			$this->autoModeratorUser,
			$this->revisionStoreMock,
			$this->config,
			$this->wikiConfig,
			$this->contentHandler,
			$this->logger,
			$this->userGroupManager,
			$this->restrictionStore,
			$this->lang,
			$this->undoSummary
		);
		$this->assertFalse( $revisionCheck->passedPreCheck );
	}
}
