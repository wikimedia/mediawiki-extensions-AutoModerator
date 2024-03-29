<?php

namespace AutoModerator\Tests;

use AutoModerator\RevisionCheck;
use ContentHandler;
use DummyContentForTesting;
use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionSlots;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Revision\RevisionStoreRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Storage\PageUpdater;
use MediaWiki\User\User;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserGroupMembership;
use MediaWikiUnitTestCase;
use MockHttpTrait;
use MockTitleTrait;
use WikiPage;

#[\AllowDynamicProperties]
/**
 * @group AutoModerator
 * @group extensions
 * @coversDefaultClass AutoModerator\RevisionCheck
 */
class RevisionCheckTest extends MediaWikiUnitTestCase {
	use MockHttpTrait;
	use MockTitleTrait;

	public function setService( $name, $service ) {
	}

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
			$revisions[] = $rev;
		}
		return array_slice( $revisions, $segment - 1, $limit );
	}

	private function getMockPage(): WikiPage {
		$ret = $this->createMock( WikiPage::class );
		$ret->method( 'canExist' )->willReturn( true );
		$ret->method( 'exists' )->willReturn( true );
		$ret->method( 'getId' )->willReturn( 1 );
		$title = $this->title;
		$title->method( 'getPrefixedText' )->willReturn( 'Foo' );
		$title->method( 'getText' )->willReturn( 'Foo' );
		$title->method( 'getDBkey' )->willReturn( 'Foo' );
		$title->method( 'getNamespace' )->willReturn( 0 );
		$ret->method( 'getTitle' )->willReturn( $title );
		$updater = $this->createMock( PageUpdater::class );
		$ret->method( 'newPageUpdater' )->willReturn( $updater );
		return $ret;
	}

	/**
	 * @return RevisionStore|MockObject
	 */
	private function getMockRevisionStore(): RevisionStore {
		$ret = $this->createMock( RevisionStore::class );
		end( $this->fakeRevisions );
		prev( $this->fakeRevisions );
		$ret->method( 'getPreviousRevision' )->willReturn( $this->rev );
		return $ret;
	}

	protected function setUp(): void {
		parent::setUp();
		$this->title = $this->makeMockTitle( 'Main_Page', [ 'id' => 1 ] );
		$this->user = $this->createMock( User::class );
		$this->wikiPageMock = $this->getMockPage();
		$this->fakeRevisions = $this->makeFakeRevisions( 3, 3 );
		$this->rev = current( $this->fakeRevisions );
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
		$this->revisionStoreMock = $this->getMockRevisionStore();
		$this->revisionStoreMock->method( 'getPreviousRevision' )->willReturn( $this->fakeRevisions[ 1 ] );
		$this->revisionStoreMock->method( 'getFirstRevision' )->willReturn( $this->fakeRevisions[ 0 ] );
		$this->changeTagsStore = $this->createMock( ChangeTagsStore::class );
		$contentHandler = $this->createMock( ContentHandler::class );
		$this->contentHandler = new $contentHandler( CONTENT_MODEL_TEXT, 'text/plain' );
		$this->contentHandler->method( 'getUndoContent' )->willReturn( new DummyContentForTesting( 'Lorem Ipsum' ) );
		$this->logger = $this->createMock( \Psr\Log\LoggerInterface::class );
		$this->userGroupManager = $this->createMock( UserGroupManager::class );
	}

	protected function tearDown(): void {
		unset(
			$this->title,
			$this->user,
			$this->wikiPageMock,
			$this->fakeRevisions,
			$this->rev,
			$this->failingScore,
			$this->passingScore,
			$this->originalRevId,
			$this->autoModeratorUser,
			$this->tags,
			$this->revisionsStoreMock,
			$this->changeTagsStore,
			$this->contentHandler,
			$this->logger,
			$this->userGroupManager,
		);
		parent::tearDown();
	}

	/**
	 * @covers ::maybeRevert
	 */
	public function testMaybeRevertBadEdit() {
		$revisionCheck = new RevisionCheck(
			$this->wikiPageMock,
			$this->rev,
			$this->originalRevId,
			$this->user,
			$this->tags,
			$this->autoModeratorUser,
			$this->revisionStoreMock,
			$this->changeTagsStore,
			$this->contentHandler,
			$this->logger,
			$this->userGroupManager,
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
			$this->wikiPageMock,
			$this->rev,
			$this->originalRevId,
			$this->user,
			$this->tags,
			$this->autoModeratorUser,
			$this->revisionStoreMock,
			$this->changeTagsStore,
			$this->contentHandler,
			$this->logger,
			$this->userGroupManager,
		);
		$reverted = array_key_first( $revisionCheck->maybeRevert(
			$this->passingScore
		) );
		$this->assertSame( 0, $reverted );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckNullEdit() {
		$this->rev->method( 'getParentId' )->willReturn( 1 );
		$revisionCheck = new RevisionCheck(
			$this->wikiPageMock,
			$this->rev,
			1,
			$this->user,
			$this->tags,
			$this->autoModeratorUser,
			$this->revisionStoreMock,
			$this->changeTagsStore,
			$this->contentHandler,
			$this->logger,
			$this->userGroupManager,
		);
		$this->assertFalse( $revisionCheck->passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckAutoModeratorEdit() {
		$this->user->method( 'equals' )->willReturn( true );
		$this->rev->method( 'getParentId' )->willReturn( 1 );
		$revisionCheck = new RevisionCheck(
			$this->wikiPageMock,
			$this->rev,
			$this->originalRevId,
			$this->user,
			$this->tags,
			$this->autoModeratorUser,
			$this->revisionStoreMock,
			$this->changeTagsStore,
			$this->contentHandler,
			$this->logger,
			$this->userGroupManager,
		);
		$this->assertFalse( $revisionCheck->passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckTagRevertEdit() {
		$this->tags = [ 'mw-manual-revert' ];
		$this->rev->method( 'getParentId' )->willReturn( 1 );
		$revisionCheck = new RevisionCheck(
			$this->wikiPageMock,
			$this->rev,
			$this->originalRevId,
			$this->user,
			$this->tags,
			$this->autoModeratorUser,
			$this->revisionStoreMock,
			$this->changeTagsStore,
			$this->contentHandler,
			$this->logger,
			$this->userGroupManager,
		);
		$this->assertFalse( $revisionCheck->passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckTagRollbackEdit() {
		$this->tags = [ 'mw-rollback' ];
		$this->rev->method( 'getParentId' )->willReturn( 1 );
		$revisionCheck = new RevisionCheck(
			$this->wikiPageMock,
			$this->rev,
			$this->originalRevId,
			$this->user,
			$this->tags,
			$this->autoModeratorUser,
			$this->revisionStoreMock,
			$this->changeTagsStore,
			$this->contentHandler,
			$this->logger,
			$this->userGroupManager,
		);
		$this->assertFalse( $revisionCheck->passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckTagUndoEdit() {
		$this->tags = [ 'mw-undo' ];
		$this->rev->method( 'getParentId' )->willReturn( 1 );
		$revisionCheck = new RevisionCheck(
			$this->wikiPageMock,
			$this->rev,
			$this->originalRevId,
			$this->user,
			$this->tags,
			$this->autoModeratorUser,
			$this->revisionStoreMock,
			$this->changeTagsStore,
			$this->contentHandler,
			$this->logger,
			$this->userGroupManager,
		);
		$this->assertFalse( $revisionCheck->passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckSysOp() {
		$this->userGroupManager->method( 'getUserGroupMemberships' )
			->willReturn( [ 'sysop' => $this->createMock( UserGroupMembership::class ) ] );
		$this->rev->method( 'getParentId' )->willReturn( 1 );
		$revisionCheck = new RevisionCheck(
			$this->wikiPageMock,
			$this->rev,
			$this->originalRevId,
			$this->user,
			$this->tags,
			$this->autoModeratorUser,
			$this->revisionStoreMock,
			$this->changeTagsStore,
			$this->contentHandler,
			$this->logger,
			$this->userGroupManager,
		);
		$this->assertFalse( $revisionCheck->passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckBot() {
		$this->userGroupManager->method( 'getUserGroupMemberships' )
			->willReturn( [ 'bot' => $this->createMock( UserGroupMembership::class ) ] );
		$this->rev->method( 'getParentId' )->willReturn( 1 );
		$revisionCheck = new RevisionCheck(
			$this->wikiPageMock,
			$this->rev,
			$this->originalRevId,
			$this->user,
			$this->tags,
			$this->autoModeratorUser,
			$this->revisionStoreMock,
			$this->changeTagsStore,
			$this->contentHandler,
			$this->logger,
			$this->userGroupManager,
		);
		$this->assertFalse( $revisionCheck->passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckMainSpaceEdit() {
		$this->wikiPageMock->method( 'getNamespace' )->willReturn( NS_MAIN );
		$this->rev->method( 'getParentId' )->willReturn( 1 );
		$revisionCheck = new RevisionCheck(
			$this->wikiPageMock,
			$this->rev,
			$this->originalRevId,
			$this->user,
			$this->tags,
			$this->autoModeratorUser,
			$this->revisionStoreMock,
			$this->changeTagsStore,
			$this->contentHandler,
			$this->logger,
			$this->userGroupManager,
		);
		$this->assertTrue( $revisionCheck->passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckNonMainSpaceEdit() {
		$this->wikiPageMock->method( 'getNamespace' )->willReturn( NS_TALK );
		$this->rev->method( 'getParentId' )->willReturn( 1 );
		$revisionCheck = new RevisionCheck(
			$this->wikiPageMock,
			$this->rev,
			$this->originalRevId,
			$this->user,
			$this->tags,
			$this->autoModeratorUser,
			$this->revisionStoreMock,
			$this->changeTagsStore,
			$this->contentHandler,
			$this->logger,
			$this->userGroupManager,
		);
		$this->assertFalse( $revisionCheck->passedPreCheck );
	}

	/**
	 * @covers ::revertPreCheck
	 */
	public function testRevertPreCheckNewPage() {
		// Override revisionStoreMock method
		$this->rev->method( 'getParentId' )->willReturn( 0 );
		$revisionCheck = new RevisionCheck(
			$this->wikiPageMock,
			$this->rev,
			$this->originalRevId,
			$this->user,
			$this->tags,
			$this->autoModeratorUser,
			$this->revisionStoreMock,
			$this->changeTagsStore,
			$this->contentHandler,
			$this->logger,
			$this->userGroupManager,
		);
		$this->assertFalse( $revisionCheck->passedPreCheck );
	}
}
