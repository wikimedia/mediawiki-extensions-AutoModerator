<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\AutoModerator\Tests;

use MediaWiki\Block\AbstractBlock;
use MediaWiki\ChangeTags\ChangeTags;
use MediaWiki\Config\Config;
use MediaWiki\Config\HashConfig;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Extension\AutoModerator\RevisionCheck;
use MediaWiki\Extension\AutoModerator\Services\AutoModeratorRollback;
use MediaWiki\Language\Language;
use MediaWiki\Page\WikiPage;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Permissions\RestrictionStore;
use MediaWiki\Revision\MutableRevisionRecord;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Tests\Mocks\Content\DummyContentForTesting;
use MediaWiki\Tests\Unit\MockServiceDependenciesTrait;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;
use MediaWikiUnitTestCase;
use MockHttpTrait;
use MockTitleTrait;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use StatusValue;

/**
 * @group AutoModerator
 * @covers \MediaWiki\Extension\AutoModerator\RevisionCheck
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
	private LoggerInterface $logger;
	private RestrictionStore $restrictionStore;
	private WikiPageFactory $wikiPageFactory;
	private RevisionStore $revisionStoreMock;
	private Config $config;
	private PermissionManager $permissionManager;
	private AutoModeratorRollback&MockObject $rollbackPage;

	protected function setUp(): void {
		parent::setUp();
		$this->config = new HashConfig( [
			'AutoModeratorMultilingualConfigEnableMultilingual' => false,
			'AutoModeratorUsername' => 'AutoModerator',
			'DisableAnonTalk' => false,
			'AutoModeratorWikiId' => 'enwiki',
			'AutoModeratorMultiLingualRevertRisk' => false,
			'AutoModeratorSkipUserRights' => [ 'bot', 'autopatrol' ],
			'AutoModeratorUseEditFlagMinor' => false,
			'AutoModeratorCautionLevel' => 'very-cautious',
			'AutoModeratorEnableLogOnlyMode' => false,
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
		[ $this->wikiPageMock, $this->rollbackPage ] = $this->getMockPageAndRollbackPage( NS_MAIN );
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

		$contentHandler = $this->createMock( ContentHandler::class );
		$this->contentHandler = new $contentHandler( CONTENT_MODEL_TEXT, 'text/plain' );
		$this->contentHandler->method( 'getUndoContent' )->willReturn( new DummyContentForTesting( 'Lorem Ipsum' ) );
		$this->logger = $this->createMock( LoggerInterface::class );
		$this->restrictionStore = $this->createMock( RestrictionStore::class );
		$this->wikiPageFactory = $this->createMock( WikiPageFactory::class );
		$this->wikiPageFactory->method( 'newFromID' )->willReturn( $this->wikiPageMock );
		$this->permissionManager = $this->createMock( PermissionManager::class );
	}

	/**
	 * cribbed from MediaWiki\Tests\Rest\Handler\UserContributionsHandlerTest
	 *
	 * @return MutableRevisionRecord[]
	 */
	private function makeFakeRevisions( int $numRevs, int $limit, int $segment = 1 ): array {
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

	private function getMockPageAndRollbackPage( int $ns, bool $isOk = true, array $errorMessages = [] ): array {
		$wikiPage = $this->createMock( WikiPage::class );
		$wikiPage->method( 'getNamespace' )->willReturn( $ns );
		$wikiPage->method( 'canExist' )->willReturn( true );
		$wikiPage->method( 'exists' )->willReturn( true );
		$wikiPage->method( 'getId' )->willReturn( 1 );
		$title = $this->title;
		$title->method( 'getPrefixedText' )->willReturn( 'Foo' );
		$title->method( 'getText' )->willReturn( 'Foo' );
		$title->method( 'getDBkey' )->willReturn( 'Foo' );
		$title->method( 'getPageLanguage' )->willReturn( $this->createMock( Language::class ) );
		$wikiPage->method( 'getTitle' )->willReturn( $title );
		$mockStatus = $this->createMock( StatusValue::class );
		$mockStatus->method( 'isOK' )->willReturn( $isOk );
		$mockStatus->method( 'getMessages' )->willReturn( $errorMessages );
		$rollbackPage = $this->createMock( AutoModeratorRollback::class );
		$rollbackPage->method( 'setSummary' )->willReturn( $rollbackPage );
		$rollbackPage->method( "rollback" )->willReturn( $mockStatus );
		return [ $wikiPage, $rollbackPage ];
	}

	private function getMockRevisionStore( $fakeRevisions, $rev ): RevisionStore&MockObject {
		$ret = $this->createMock( RevisionStore::class );
		end( $fakeRevisions );
		prev( $fakeRevisions );
		$ret->method( 'getPreviousRevision' )->willReturn( $rev );
		$ret->method( 'getRevisionById' )->willReturn( $rev );
		return $ret;
	}

	public function testMaybeRollbackBadEdit() {
		$revisionCheck = new RevisionCheck(
			$this->config,
			$this->rollbackPage,
			true
		);
		$reverted = $revisionCheck->maybeRollback(
			$this->failingScore
		)->isReverted();
		$this->assertSame( true, $reverted );
	}

	public function testMaybeRollbackBadEditInLogOnlyMode() {
		$this->config = new HashConfig( [
			'AutoModeratorMultilingualConfigEnableMultilingual' => false,
			'AutoModeratorUsername' => 'AutoModerator',
			'DisableAnonTalk' => false,
			'AutoModeratorWikiId' => 'enwiki',
			'AutoModeratorMultiLingualRevertRisk' => false,
			'AutoModeratorSkipUserRights' => [ 'bot', 'autopatrol' ],
			'AutoModeratorUseEditFlagMinor' => false,
			'AutoModeratorCautionLevel' => 'very-cautious',
			'AutoModeratorEnableLogOnlyMode' => true,
		] );
		$revisionCheck = new RevisionCheck(
			$this->config,
			$this->rollbackPage,
			true
		);
		$rollbackStatus = $revisionCheck->maybeRollback(
			$this->failingScore
		);
		$this->assertSame( false, $rollbackStatus->isReverted() );
		$this->assertSame( "Not reverted", $rollbackStatus->getStatus() );
		$this->assertSame( true, $rollbackStatus->shouldRevert() );
	}

	public function testMaybeRollbackGoodEditInLogOnlyMode() {
		$this->config = new HashConfig( [
			'AutoModeratorMultilingualConfigEnableMultilingual' => false,
			'AutoModeratorUsername' => 'AutoModerator',
			'DisableAnonTalk' => false,
			'AutoModeratorWikiId' => 'enwiki',
			'AutoModeratorMultiLingualRevertRisk' => false,
			'AutoModeratorSkipUserRights' => [ 'bot', 'autopatrol' ],
			'AutoModeratorUseEditFlagMinor' => false,
			'AutoModeratorCautionLevel' => 'very-cautious',
			'AutoModeratorEnableLogOnlyMode' => true,
		] );
		$revisionCheck = new RevisionCheck(
			$this->config,
			$this->rollbackPage,
			true
		);
		$rollbackStatus = $revisionCheck->maybeRollback(
			$this->passingScore
		);
		$this->assertSame( false, $rollbackStatus->isReverted() );
		$this->assertSame( "Not reverted", $rollbackStatus->getStatus() );
		$this->assertSame( false, $rollbackStatus->shouldRevert() );
	}

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
			$this->config,
			$rollbackPage,
			true
		);
		$rollbackStatus = $revisionCheck->maybeRollback(
			$this->failingScore,
		);
		$this->assertSame( false, $rollbackStatus->isReverted() );
		$this->assertSame( "Generic Error Message", $rollbackStatus->getStatus() );
	}

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
			$this->config,
			$rollbackPage,
			true
		);
		$rollbackStatus = $revisionCheck->maybeRollback(
			$this->failingScore
		);
		$this->assertSame( false, $rollbackStatus->isReverted() );
		$this->assertSame( "success", $rollbackStatus->getStatus() );
	}

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
			$this->config,
			$rollbackPage,
			true
		);
		$rollbackStatus = $revisionCheck->maybeRollback(
			$this->failingScore
		);
		$this->assertSame( false, $rollbackStatus->isReverted() );
		$this->assertSame( "success", $rollbackStatus->getStatus() );
	}

	public function testMaybeRollbackBadSaveStatusNoMessage() {
		$wikiPageFactory = $this->createMock( WikiPageFactory::class );
		$wikiTestPages = $this->getMockPageAndRollbackPage( NS_MAIN,
			false );
		$wikiPage  = $wikiTestPages[0];
		$rollbackPage = $wikiTestPages[1];
		$wikiPage->method( 'getRevisionRecord' )->willReturn( $this->fakeRevisions[ 2 ] );
		$wikiPageFactory->method( 'newFromID' )->willReturn( $wikiPage );
		$revisionCheck = new RevisionCheck(
			$this->config,
			$rollbackPage,
			true
		);
		$rollbackStatus = $revisionCheck->maybeRollback(
			$this->failingScore
		);
		$this->assertSame( false, $rollbackStatus->isReverted() );
		$this->assertSame( "Failed to save revision", $rollbackStatus->getStatus() );
	}

	public function testMaybeRollbackGoodEdit() {
		$revisionCheck = new RevisionCheck(
			$this->config,
			$this->rollbackPage
		);
		$rollbackStatus = $revisionCheck->maybeRollback(
			$this->passingScore
		);
		$this->assertSame( false, $rollbackStatus->isReverted() );
		$this->assertSame( 'Not reverted', $rollbackStatus->getStatus() );
	}

	/**
	 * With lower than minimum threshold configured and passing score.
	 */
	public function testMaybeRollbackWithLowThresholdSuccess() {
		$revisionCheck = new RevisionCheck(
			$this->config,
			$this->rollbackPage
		);

		$reverted = $revisionCheck->maybeRollback(
			$this->passingScore
		)->isReverted();
		$this->assertSame( false, $reverted );
	}

	/**
	 * With lower than minimum threshold configured and failing score.
	 */
	public function testMaybeRollbackWithLowThresholdFailing() {
		$revisionCheck = new RevisionCheck(
			$this->config,
			$this->rollbackPage,
			true
		);

		$reverted = $revisionCheck->maybeRollback(
			$this->failingScore,
		)->isReverted();
		$this->assertSame( true, $reverted );
	}

	public function testRevertPreCheckAutoModeratorEdit() {
		$passedPreCheck = RevisionCheck::revertPreCheck(
			$this->selfUser,
			$this->autoModeratorUser,
			$this->logger,
			$this->revisionStoreMock,
			$this->tags,
			$this->restrictionStore,
			$this->wikiPageFactory,
			$this->config,
			$this->rev,
			$this->permissionManager
		);
		$this->assertFalse( $passedPreCheck );
	}

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
			$this->config,
			$this->rev,
			$this->permissionManager
		);
		$this->assertFalse( $passedPreCheck );
	}

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
			$this->config,
			$this->rev,
			$this->permissionManager
		) );
	}

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
			$this->config,
			$this->rev,
			$this->permissionManager
		) );
	}

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
			$this->config,
			$this->rev,
			$this->permissionManager
		) );
	}

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
			$this->config,
			$this->rev,
			$this->permissionManager
		);
		$this->assertTrue( $passedPreCheck );
	}

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
			$this->config,
			$this->rev,
			$this->permissionManager
		);
		$this->assertFalse( $passedPreCheck );
	}

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
			$this->config,
			$this->rev,
			$this->permissionManager
		);
		$this->assertTrue( $passedPreCheck );
	}

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
			$this->config,
			$this->rev,
			$this->permissionManager
		);
		$this->assertFalse( $passedPreCheck );
	}

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
			$this->config,
			$this->rev,
			$this->permissionManager
		);
		$this->assertTrue( $passedPreCheck );
	}

	public static function provideRevertPreCheckSkipsOthersTaggedEdit(): array {
		return [
			[ ChangeTags::TAG_REVERTED ],
			[ ChangeTags::TAG_NEW_REDIRECT ],
			[ ChangeTags::TAG_REMOVED_REDIRECT ],
			[ ChangeTags::TAG_CHANGED_REDIRECT_TARGET ],
		];
	}

	/**
	 * @dataProvider provideRevertPreCheckSkipsOthersTaggedEdit
	 */
	public function testRevertPreCheckSkipsTaggedEdit( string $tag ) {
		$this->tags = [ $tag ];
		$passedPreCheck = RevisionCheck::revertPreCheck(
			$this->user,
			$this->autoModeratorUser,
			$this->logger,
			$this->revisionStoreMock,
			$this->tags,
			$this->restrictionStore,
			$this->wikiPageFactory,
			$this->config,
			$this->rev,
			$this->permissionManager
		);
		$this->assertFalse( $passedPreCheck );
	}

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
			$this->config,
			$this->rev,
			$this->permissionManager
		);
		$this->assertFalse( $passedPreCheck );
	}

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
			$this->config,
			$this->rev,
			$this->permissionManager
		);
		$this->assertFalse( $passedPreCheck );
	}

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
			$this->config,
			$this->rev,
			$this->permissionManager
		);
		$this->assertFalse( $passedPreCheck );
	}

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
			$this->config,
			$mockRevision,
			$this->permissionManager
		);
		$this->assertFalse( $passedPreCheck );
	}

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
			$this->config,
			$mockRevision,
			$this->permissionManager
		);
		$this->assertTrue( $passedPreCheck );
	}

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
			$this->config,
			$mockRevision,
			$this->permissionManager
		);
		$this->assertFalse( $passedPreCheck );
	}

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
			$this->config,
			$mockRevision,
			$this->permissionManager
		);
		$this->assertTrue( $passedPreCheck );
	}

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
			$this->config,
			$mockRevision,
			$this->permissionManager
		);
		$this->assertFalse( $passedPreCheck );
	}

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
			$this->config,
			$mockRevision,
			$this->permissionManager
		);
		$this->assertTrue( $passedPreCheck );
	}

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
			$this->config,
			$mockRevision,
			$this->permissionManager
		);
		$this->assertFalse( $passedPreCheck );
	}

	public function testRevertPreCheckTrueWhenParentRevisionUser() {
		$this->autoModeratorUser = $this->createMock( User::class );
		$this->autoModeratorUser->method( 'equals' )->willReturn( true );

		$this->tags = [ ChangeTags::TAG_MANUAL_REVERT ];
		$this->revisionStoreMock = $this->createMock( RevisionStore::class );

		$mockRevision = $this->createMock( RevisionRecord::class );
		$mockRevision->method( 'getParentId' )->willReturn( 1 );
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
			$this->config,
			$mockRevision,
			$this->permissionManager
		);
		$this->assertTrue( $passedPreCheck );
	}

	public function testRevertPreCheckMainSpaceEdit() {
		$passedPreCheck = RevisionCheck::revertPreCheck(
			$this->user,
			$this->autoModeratorUser,
			$this->logger,
			$this->revisionStoreMock,
			$this->tags,
			$this->restrictionStore,
			$this->wikiPageFactory,
			$this->config,
			$this->rev,
			$this->permissionManager
		);
		$this->assertTrue( $passedPreCheck );
	}

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
			$this->config,
			$this->rev,
			$this->permissionManager
		);
		$this->assertFalse( $passedPreCheck );
	}

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
			$this->config,
			$rev,
			$this->permissionManager
		);
		$this->assertFalse( $passedPreCheck );
	}

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
			$this->config,
			$this->rev,
			$this->permissionManager
		);
		$this->assertFalse( $passedPreCheck );
	}

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
			$this->config,
			$this->rev,
			$this->permissionManager
		);
		$this->assertFalse( $passedPreCheck );
	}

	public function testShouldSkipUserTrue() {
		$this->config->set( 'AutoModeratorSkipUserRights', 'bot' );
		$this->permissionManager->method( 'userHasAnyRight' )->willReturn( true );
		$this->assertTrue(
			RevisionCheck::shouldSkipUser( $this->permissionManager,
				$this->autoModeratorUser, $this->config )
		);
	}

	public function testShouldSkipUserFalse() {
		$this->config->set( 'AutoModeratorSkipUserRights', 'bot' );
		$this->permissionManager->method( 'userHasAnyRight' )->willReturn( false );
		$this->assertFalse(
			RevisionCheck::shouldSkipUser( $this->permissionManager, $this->user, $this->config ) );
	}

	public function testAreUsersEqual() {
		$this->autoModeratorUser->method( "equals" )->willReturn( true );
		$this->assertTrue( RevisionCheck::areUsersEqual( $this->autoModeratorUser, $this->autoModeratorUser ) );
	}

	public function testAreUsersEqualNotEqual() {
		$this->assertFalse( RevisionCheck::areUsersEqual( $this->user, $this->autoModeratorUser ) );
	}

	public function testIsProtectedPageTrue() {
		$this->restrictionStore->method( 'isProtected' )->willReturn( true );
		$this->restrictionStore->method( 'isSemiProtected' )->willReturn( false );
		$this->assertTrue( RevisionCheck::isProtectedPage( $this->restrictionStore, $this->wikiPageMock ) );
	}

	public function testIsProtectedPageFalse() {
		$this->restrictionStore->method( 'isProtected' )->willReturn( false );
		$this->assertFalse( RevisionCheck::isProtectedPage( $this->restrictionStore, $this->wikiPageMock ) );
	}

	public function testIsProtectedPageFalseWhenSemiProtected() {
		$this->restrictionStore->method( 'isProtected' )->willReturn( true );
		$this->restrictionStore->method( 'isSemiProtected' )->willReturn( true );
		$this->assertFalse( RevisionCheck::isProtectedPage( $this->restrictionStore, $this->wikiPageMock ) );
	}

	public function testIsNewPageCreationTrueWhenParentIdIsNull() {
		$this->assertTrue( RevisionCheck::isNewPageCreation( null ) );
	}

	public function testIsNewPageCreationTrueWhenParentIdIsZero() {
		$this->assertTrue( RevisionCheck::isNewPageCreation( 0 ) );
	}

	public function testIsNewPageCreationFalseWhenSet() {
		$this->assertFalse( RevisionCheck::isNewPageCreation( 2 ) );
	}
}
