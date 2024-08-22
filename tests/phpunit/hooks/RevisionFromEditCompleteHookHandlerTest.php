<?php

namespace AutoModerator\Tests\Hooks;

use AutoModerator\Config\AutoModeratorWikiConfigLoader;
use AutoModerator\Config\WikiPageConfig;
use AutoModerator\Hooks\RevisionFromEditCompleteHookHandler;
use AutoModerator\Util;
use MediaWiki\Config\HashConfig;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Permissions\RestrictionStore;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentity;
use WikiPage;

/**
 * @group AutoModerator
 * @group Database
 * @covers \AutoModerator\Hooks\RevisionFromEditCompleteHookHandler
 */
class RevisionFromEditCompleteHookHandlerTest extends \MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
	}

	public function provideOnRevisionFromEditCompleteQueued(): array {
		$wikiPage = $this->createMock( WikiPage::class );
		$wikiPage->method( 'getId' )->willReturn( 1 );
		$wikiPage->method( 'getNamespace' )->willReturn( NS_MAIN );
		$wikiPage->method( 'getTitle' )->willReturn( $this->createMock( Title::class ) );
		$rev = $this->createMock( RevisionRecord::class );
		$rev->method( 'getId' )->willReturn( 1000 );
		$mockSlotRecord = $this->createMock( SlotRecord::class );
		$mockSlotRecord->method( 'getModel' )->willReturn( "wikitext" );
		$rev->method( 'getSlot' )->willReturn( $mockSlotRecord );
		$user = $this->createMock( UserIdentity::class );
		$user->method( 'getId' )->willReturn( 1000 );
		$user->method( 'getName' )->willReturn( 'TestUser1000' );

		return [
			[ $wikiPage, $rev, false, $user, [] ]
		];
	}

	public function provideOnRevisionFromEditCompleteQueuedTalkPageMessageJob(): array {
		$wikiPage = $this->createMock( WikiPage::class );
		$wikiPage->method( 'getNamespace' )->willReturn( NS_MAIN );
		$wikiPage->method( 'getId' )->willReturn( 1 );
		$wikiPage->method( 'getTitle' )->willReturn( $this->createMock( Title::class ) );
		$rev = $this->createMock( RevisionRecord::class );
		$rev->method( 'getUser' )->willReturn( $user = $this->createMock( User::class ) );
		$rev->method( 'getId' )->willReturn( 1000 );
		$rev->method( 'getParentId' )->willReturn( 999 );
		$user = $this->createMock( UserIdentity::class );
		$user->method( 'getId' )->willReturn( 1000 );
		$user->method( 'getName' )->willReturn( 'TestUser1000' );
		return [
			[ $wikiPage, $rev, false, $user, [ 'mw-undo' ] ]
		];
	}

	/**
	 * @dataProvider provideOnRevisionFromEditCompleteQueued
	 */
	public function testOnRevisionFromEditCompleteQueued( $wikiPage, $rev, $originalRevId, $user, $tags ) {
		$jobQueueGroup = $this->getServiceContainer()->getJobQueueGroup();
		$jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->delete();
		$wikiConfig = $this->createMock( WikiPageConfig::class );
		$wikiConfig->expects( $this->never() )->method( 'getWithFlags' );
		$wikiConfig->method( "get" )->willReturn( true );
		$autoModWikiConfig = new AutoModeratorWikiConfigLoader(
			$wikiConfig,
			new HashConfig( [
				'AutoModeratorEnableWikiConfig' => true,
				'AutoModeratorEnableRevisionCheck' => true,
				'AutoModeratorUsername' => 'AutoModerator',
				'AutoModeratorSkipUserRights' => [],
			] )
		);
		$config = new HashConfig( [
			'DisableAnonTalk' => false,
			'AutoModeratorEnableWikiConfig' => true,
			'AutoModeratorEnableRevisionCheck' => true,
			'AutoModeratorUsername' => 'AutoModerator',
			'AutoModeratorWikiId' => 'enwiki',
		] );
		$userGroupManager = $this->createMock( UserGroupManager::class );
		$mockUtil = $this->createMock( Util::class );
		$mockUser = $this->createMock( User::class );
		$wikiPageFactory = $this->createMock( WikiPageFactory::class );
		$mockRevision = $this->createMock( RevisionRecord::class );
		$mockRestrictionStore = $this->createMock( RestrictionStore::class );
		$mockRevisionStore = $this->createMock( RevisionStore::class );
		$mockUtil->method( 'getAutoModeratorUser' )->willReturn( $mockUser );
		$wikiPageFactory->method( 'newFromID' )->willReturn( $wikiPage );
		$mockRevision->method( 'getParentId' )->willReturn( 100 );
		$mockRevisionStore->method( 'getRevisionById' )->willReturn( $mockRevision );
		$mockRestrictionStore->method( 'isProtected' )->willReturn( false );
		$mockPermissionManager = $this->createMock( PermissionManager::class );

		( new RevisionFromEditCompleteHookHandler( $autoModWikiConfig, $userGroupManager,
			$config, $wikiPageFactory, $mockRevisionStore, $mockRestrictionStore,
			$jobQueueGroup, $mockPermissionManager ) )
			->onRevisionFromEditComplete( $wikiPage, $rev, $originalRevId, $user, $tags );

		$actual = $jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->pop()->getParams();
		$actual['requestId'] = 42;
		// Disabling line too long rule as line is too long for phpcs,
		// but we need to check for strict equality without newline breaks
		// phpcs:disable Generic.Files.LineLength.TooLong
		$undoSummary = "Undo revision [[Special:Diff/1000|1000]] by [[Special:Contributions/TestUser1000|TestUser1000]] ([[User talk:TestUser1000|talk]])";
		// phpcs:enable
		$expected = [
			'wikiPageId' => 1,
			'revId' => 1000,
			'originalRevId' => false,
			'userId' => $user->getId(),
			'userName' => $user->getName(),
			'tags' => [],
			'namespace' => NS_MAIN,
			'title' => '',
			'requestId' => 42,
			'undoSummary' => $undoSummary
		];
		$this->assertEquals( $expected, $actual );
	}

	/**
	 * @dataProvider provideOnRevisionFromEditCompleteQueued
	 */
	public function testOnRevisionFromEditCompleteQueuedWhenUserAnon( $wikiPage, $rev, $originalRevId, $user, $tags ) {
		$jobQueueGroup = $this->getServiceContainer()->getJobQueueGroup();
		$jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->delete();
		$wikiConfig = $this->createMock( WikiPageConfig::class );
		$wikiConfig->expects( $this->never() )->method( 'getWithFlags' );
		$wikiConfig->method( "get" )->willReturn( true );
		$autoModWikiConfig = new AutoModeratorWikiConfigLoader(
			$wikiConfig,
			new HashConfig( [
				'AutoModeratorEnableWikiConfig' => true,
				'AutoModeratorEnableRevisionCheck' => true,
				'AutoModeratorUsername' => 'AutoModerator',
				'AutoModeratorSkipUserRights' => [],
			] )
		);
		$config = new HashConfig( [
			'DisableAnonTalk' => true,
			'AutoModeratorEnableWikiConfig' => true,
			'AutoModeratorEnableRevisionCheck' => true,
			'AutoModeratorUsername' => 'AutoModerator',
			'AutoModeratorWikiId' => 'enwiki',
		] );
		$userGroupManager = $this->createMock( UserGroupManager::class );
		$mockUtil = $this->createMock( Util::class );
		$mockUser = $this->createMock( User::class );
		$wikiPageFactory = $this->createMock( WikiPageFactory::class );
		$mockRevisionStore = $this->createMock( RevisionStore::class );
		$mockRevision = $this->createMock( RevisionRecord::class );
		$mockRestrictionStore = $this->createMock( RestrictionStore::class );
		$mockUtil->method( 'getAutoModeratorUser' )->willReturn( $mockUser );
		$wikiPageFactory->method( 'newFromID' )->willReturn( $wikiPage );
		$mockRevision->method( 'getParentId' )->willReturn( 100 );
		$mockRevisionStore->method( 'getRevisionById' )->willReturn( $mockRevision );
		$mockRestrictionStore->method( 'isProtected' )->willReturn( false );
		$mockPermissionManager = $this->createMock( PermissionManager::class );

		( new RevisionFromEditCompleteHookHandler( $autoModWikiConfig, $userGroupManager,
			$config, $wikiPageFactory, $mockRevisionStore, $mockRestrictionStore, $jobQueueGroup,
			$mockPermissionManager ) )
			->onRevisionFromEditComplete( $wikiPage, $rev, $originalRevId, $user, $tags );

		$actual = $jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->pop()->getParams();
		$actual['requestId'] = 42;
		$expected = [
			'wikiPageId' => 1,
			'revId' => 1000,
			'originalRevId' => false,
			'userId' => $user->getId(),
			'userName' => $user->getName(),
			'tags' => [],
			'namespace' => NS_MAIN,
			'title' => '',
			'requestId' => 42,
			'undoSummary' =>
				"Undo revision [[Special:Diff/1000|1000]] by [[Special:Contributions/TestUser1000|TestUser1000]]"
		];
		$this->assertEquals( $expected, $actual );
	}

	public function provideOnRevisionFromEditCompleteMainNotQueued() {
		$wikiPage = $this->createMock( WikiPage::class );
		$wikiPage->method( 'getNamespace' )->willReturn( NS_MAIN );
		$rev = $this->createMock( RevisionRecord::class );
		$user = $this->createMock( UserIdentity::class );
		return [
			[ null, $rev, false, $user, [] ],
			[ $wikiPage, null, false, $user, [] ],
			[ $wikiPage, $rev, false, null, [] ],
		];
	}

	/**
	 * @dataProvider provideOnRevisionFromEditCompleteMainNotQueued
	 */
	public function testOnRevisionFromEditCompleteMainNotQueued( $wikiPage, $rev, $originalRevId, $user, $tags ) {
		$jobQueueGroup = $this->getServiceContainer()->getJobQueueGroup();
		$jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->delete();
		$wikiPageFactory = $this->getServiceContainer()->getWikiPageFactory();
		$wikiConfig = $this->createMock( WikiPageConfig::class );
		$wikiConfig->expects( $this->never() )->method( 'hasWithFlags' );
		$wikiConfig->expects( $this->never() )->method( 'getWithFlags' );
		$autoModWikiConfig = new AutoModeratorWikiConfigLoader(
			$wikiConfig,
			new HashConfig( [
				'AutoModeratorEnableWikiConfig' => true,
				'AutoModeratorEnableRevisionCheck' => true,
				'AutoModeratorUsername' => 'AutoModerator',
			] )
		);
		$config = new HashConfig( [
			'AutoModeratorEnableWikiConfig' => true,
			'AutoModeratorEnableRevisionCheck' => true,
			'AutoModeratorUsername' => 'AutoModerator',
		] );
		$userGroupManager = $this->createMock( UserGroupManager::class );
		$mockUtil = $this->createMock( Util::class );
		$mockUser = $this->createMock( User::class );
		$mockUtil->method( 'getAutoModeratorUser' )->willReturn( $mockUser );
		$mockRevisionStore = $this->createMock( RevisionStore::class );
		$mockRevision = $this->createMock( RevisionRecord::class );
		$mockRevision->method( 'getParentId' )->willReturn( 100 );
		$mockRevisionStore->method( 'getRevisionById' )->willReturn( $mockRevision );
		$mockRestrictionStore = $this->createMock( RestrictionStore::class );
		$mockRestrictionStore->method( 'isProtected' )->willReturn( false );
		$mockPermissionManager = $this->createMock( PermissionManager::class );

		( new RevisionFromEditCompleteHookHandler( $autoModWikiConfig, $userGroupManager,
			$config, $wikiPageFactory, $mockRevisionStore, $mockRestrictionStore, $jobQueueGroup,
			$mockPermissionManager ) )
			->onRevisionFromEditComplete( $wikiPage, $rev, $originalRevId, $user, $tags );

		$this->assertFalse( $jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->pop() );
	}

	public function provideOnRevisionFromEditCompleteTalkNotQueued() {
		$wikiPage = $this->createMock( WikiPage::class );
		$wikiPage->method( 'getNamespace' )->willReturn( NS_TALK );
		$rev = $this->createMock( RevisionRecord::class );
		$user = $this->createMock( UserIdentity::class );
		return [
			[ $wikiPage, $rev, false, $user, [] ]
		];
	}

	/**
	 * @dataProvider provideOnRevisionFromEditCompleteTalkNotQueued
	 */
	public function testOnRevisionFromEditCompleteTalkNotQueued( $wikiPage, $rev, $originalRevId, $user, $tags ) {
		$jobQueueGroup = $this->getServiceContainer()->getJobQueueGroup();
		$jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->delete();
		$wikiPageFactory = $this->createMock( WikiPageFactory::class );
		$wikiPageFactory->method( 'newFromID' )->willReturn( $wikiPage );
		$wikiConfig = $this->createMock( WikiPageConfig::class );
		$wikiConfig->expects( $this->atLeastOnce() )->method( 'hasWithFlags' );
		$wikiConfig->expects( $this->never() )->method( 'getWithFlags' );
		$wikiConfig->method( "get" )->willReturn( true );
		$autoModWikiConfig = new AutoModeratorWikiConfigLoader(
			$wikiConfig,
			new HashConfig( [
				'AutoModeratorEnableWikiConfig' => true,
				'AutoModeratorEnableRevisionCheck' => true,
				'AutoModeratorUsername' => 'AutoModerator',
				'AutoModeratorSkipUserRights' => [ 'bot', 'autopatrol' ],
			] )
		);
		$config = new HashConfig( [
			'DisableAnonTalk' => true,
			'AutoModeratorEnableWikiConfig' => true,
			'AutoModeratorEnableRevisionCheck' => true,
			'AutoModeratorUsername' => 'AutoModerator',
			'AutoModeratorSkipUserRights' => [ 'bot', 'autopatrol' ],
		] );
		$userGroupManager = $this->createMock( UserGroupManager::class );
		$mockUtil = $this->createMock( Util::class );
		$mockUser = $this->createMock( User::class );
		$mockRevisionStore = $this->createMock( RevisionStore::class );
		$mockRevision = $this->createMock( RevisionRecord::class );
		$mockRestrictionStore = $this->createMock( RestrictionStore::class );

		$mockUtil->method( 'getAutoModeratorUser' )->willReturn( $mockUser );
		$mockRevision->method( 'getId' )->willReturn( 101 );
		$mockRevision->method( 'getParentId' )->willReturn( 100 );
		$mockRevisionStore->method( 'getRevisionById' )->willReturn( $mockRevision );
		$mockRestrictionStore->method( 'isProtected' )->willReturn( false );
		$mockPermissionManager = $this->createMock( PermissionManager::class );

		( new RevisionFromEditCompleteHookHandler( $autoModWikiConfig, $userGroupManager,
			$config, $wikiPageFactory, $mockRevisionStore, $mockRestrictionStore, $jobQueueGroup,
			$mockPermissionManager ) )
			->onRevisionFromEditComplete( $wikiPage, $mockRevision, $originalRevId, $user, $tags );

		$this->assertFalse( $jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->pop() );
	}

	/**
	 * @dataProvider provideOnRevisionFromEditCompleteQueuedTalkPageMessageJob
	 */
	public function testOnRevisionFromEditCompleteTalkNotQueuedWhenMissingParentRev( $wikiPage,
			$rev, $originalRevId, $user, $tags ) {
			$jobQueueGroup = $this->getServiceContainer()->getJobQueueGroup();
			$jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->delete();
			$wikiPageFactory = $this->createMock( WikiPageFactory::class );
			$wikiPageFactory->method( 'newFromID' )->willReturn( $wikiPage );
			$wikiConfig = $this->createMock( WikiPageConfig::class );
			$autoModWikiConfig = new AutoModeratorWikiConfigLoader(
				$wikiConfig,
				new HashConfig( [
					'AutoModeratorEnableWikiConfig' => true,
					'AutoModeratorEnableRevisionCheck' => true,
					'AutoModeratorUsername' => 'AutoModerator',
					'AutoModeratorRevertTalkPageMessageEnabled' => true,
					'AutoModeratorFalsePositivePageTitle' => "",
				] )
			);
			$config = new HashConfig( [
				'DisableAnonTalk' => true,
				'AutoModeratorEnableWikiConfig' => true,
				'AutoModeratorEnableRevisionCheck' => true,
				'AutoModeratorUsername' => 'AutoModerator',
				'AutoModeratorWikiId' => "en"
			] );
			$userGroupManager = $this->createMock( UserGroupManager::class );
			$mockRevisionStore = $this->createMock( RevisionStore::class );
			$mockRestrictionStore = $this->createMock( RestrictionStore::class );
			$mockRevisionStore->method( 'getRevisionById' )->willReturn( null );
			$mockPermissionManager = $this->createMock( PermissionManager::class );

			( new RevisionFromEditCompleteHookHandler( $autoModWikiConfig, $userGroupManager,
				$config, $wikiPageFactory, $mockRevisionStore, $mockRestrictionStore, $jobQueueGroup,
				$mockPermissionManager ) )
				->onRevisionFromEditComplete( $wikiPage, $rev, $originalRevId,
					Util::getAutoModeratorUser( $config, $userGroupManager ), $tags );
			$this->assertFalse( $jobQueueGroup->get( 'AutoModeratorSendRevertTalkPageMsgJob' )->pop() );
	}

	/**
	 * @dataProvider provideOnRevisionFromEditCompleteQueuedTalkPageMessageJob
	 */
	public function testOnRevisionFromEditCompleteTalkNotQueuedWhenMissingParentRevId( $wikiPage,
			$rev, $originalRevId, $user, $tags ) {
		$jobQueueGroup = $this->getServiceContainer()->getJobQueueGroup();
		$jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->delete();
		$wikiPageFactory = $this->createMock( WikiPageFactory::class );
		$wikiPageFactory->method( 'newFromID' )->willReturn( $wikiPage );
		$wikiConfig = $this->createMock( WikiPageConfig::class );
		$autoModWikiConfig = new AutoModeratorWikiConfigLoader(
			$wikiConfig,
			new HashConfig( [
				'AutoModeratorEnableWikiConfig' => true,
				'AutoModeratorEnableRevisionCheck' => true,
				'AutoModeratorUsername' => 'AutoModerator',
				'AutoModeratorRevertTalkPageMessageEnabled' => true,
				'AutoModeratorFalsePositivePageTitle' => "",
			] )
		);
		$config = new HashConfig( [
			'DisableAnonTalk' => true,
			'AutoModeratorEnableWikiConfig' => true,
			'AutoModeratorEnableRevisionCheck' => true,
			'AutoModeratorUsername' => 'AutoModerator',
			'AutoModeratorWikiId' => "en"
		] );
		$userGroupManager = $this->createMock( UserGroupManager::class );
		$mockRevisionStore = $this->createMock( RevisionStore::class );
		$mockRestrictionStore = $this->createMock( RestrictionStore::class );

		$mockRevision = $this->createMock( RevisionRecord::class );
		$mockRevision->method( 'getParentId' )->willReturn( null );
		$mockRevisionStore->method( 'getRevisionById' )->willReturn( $mockRevision );
		$mockPermissionManager = $this->createMock( PermissionManager::class );

		( new RevisionFromEditCompleteHookHandler( $autoModWikiConfig, $userGroupManager,
			$config, $wikiPageFactory, $mockRevisionStore, $mockRestrictionStore, $jobQueueGroup,
			$mockPermissionManager ) )
			->onRevisionFromEditComplete( $wikiPage, $rev, $originalRevId,
				Util::getAutoModeratorUser( $config, $userGroupManager ), $tags );
		$this->assertFalse( $jobQueueGroup->get( 'AutoModeratorSendRevertTalkPageMsgJob' )->pop() );
	}

	/**
	 * @dataProvider provideOnRevisionFromEditCompleteQueuedTalkPageMessageJob
	 */
	public function testOnRevisionFromEditCompleteAutoModeratorSendRevertTalkPageMsgJobQueued(
		$wikiPage, $rev, $originalRevId, $user, $tags ) {
		$jobQueueGroup = $this->getServiceContainer()->getJobQueueGroup();
		$jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->delete();
		$wikiPageFactory = $this->createMock( WikiPageFactory::class );
		$wikiPageFactory->method( 'newFromID' )->willReturn( $wikiPage );
		$wikiConfig = $this->createMock( WikiPageConfig::class );
		$autoModWikiConfig = new AutoModeratorWikiConfigLoader(
			$wikiConfig,
			new HashConfig( [
				'AutoModeratorEnableWikiConfig' => true,
				'AutoModeratorEnableRevisionCheck' => true,
				'AutoModeratorUsername' => 'AutoModerator',
				'AutoModeratorRevertTalkPageMessageEnabled' => true,
				'AutoModeratorFalsePositivePageTitle' => "",
			] )
		);
		$config = new HashConfig( [
			'DisableAnonTalk' => true,
			'AutoModeratorEnableWikiConfig' => true,
			'AutoModeratorEnableRevisionCheck' => true,
			'AutoModeratorUsername' => 'AutoModerator',
			'AutoModeratorWikiId' => "en"
		] );
		$userGroupManager = $this->createMock( UserGroupManager::class );
		$mockRevisionStore = $this->createMock( RevisionStore::class );
		$mockRestrictionStore = $this->createMock( RestrictionStore::class );

		$mockRevisionStore->method( "getRevisionById" )->willReturn( $rev );
		$mockPermissionManager = $this->createMock( PermissionManager::class );

		( new RevisionFromEditCompleteHookHandler( $autoModWikiConfig, $userGroupManager,
			$config, $wikiPageFactory, $mockRevisionStore, $mockRestrictionStore, $jobQueueGroup,
			$mockPermissionManager ) )
			->onRevisionFromEditComplete( $wikiPage, $rev, $originalRevId,
				Util::getAutoModeratorUser( $config, $userGroupManager ), $tags );
		$this->assertNotFalse( $jobQueueGroup->get( 'AutoModeratorSendRevertTalkPageMsgJob' )->pop() );
	}

	/**
	 * @dataProvider provideOnRevisionFromEditCompleteQueuedTalkPageMessageJob
	 * @throws \JobQueueError
	 */
	public function testOnRevisionFromEditCompleteAutoModeratorSendRevertTalkPageMsgJobNotQueuedWhenNotUndoTag(
		$wikiPage, $rev, $originalRevId, $user, $tags ) {
		$jobQueueGroup = $this->getServiceContainer()->getJobQueueGroup();
		$jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->delete();
		$wikiPageFactory = $this->createMock( WikiPageFactory::class );
		$wikiPageFactory->method( 'newFromID' )->willReturn( $wikiPage );
		$wikiConfig = $this->createMock( WikiPageConfig::class );
		$autoModWikiConfig = new AutoModeratorWikiConfigLoader(
			$wikiConfig,
			new HashConfig( [
				'AutoModeratorEnableWikiConfig' => true,
				'AutoModeratorEnableRevisionCheck' => true,
				'AutoModeratorUsername' => 'AutoModerator',
				'AutoModeratorRevertTalkPageMessageEnabled' => true,
				'AutoModeratorRevertTalkPageMessageHeading' => "heading",
				'AutoModeratorRevertTalkPageMessageEditSummary' => "edit summary",
				'AutoModeratorFalsePositivePageTitle' => "",

			] )
		);
		$config = new HashConfig( [
			'AutoModeratorEnableWikiConfig' => true,
			'AutoModeratorEnableRevisionCheck' => true,
			'AutoModeratorUsername' => 'AutoModerator',
			'AutoModeratorWikiId' => "en"
		] );
		$userGroupManager = $this->createMock( UserGroupManager::class );
		$mockRevisionStore = $this->createMock( RevisionStore::class );
		$mockRestrictionStore = $this->createMock( RestrictionStore::class );

		$mockRevisionStore->method( "getRevisionById" )->willReturn( $rev );
		$tags = [];
		$mockPermissionManager = $this->createMock( PermissionManager::class );

		( new RevisionFromEditCompleteHookHandler( $autoModWikiConfig, $userGroupManager,
			$config, $wikiPageFactory, $mockRevisionStore, $mockRestrictionStore, $jobQueueGroup,
			$mockPermissionManager ) )
			->onRevisionFromEditComplete( $wikiPage, $rev, $originalRevId,
				Util::getAutoModeratorUser( $config, $userGroupManager ), $tags );
		$this->assertFalse( $jobQueueGroup->get( 'AutoModeratorSendRevertTalkPageMsgJob' )->pop() );
	}

	/**
	 * @dataProvider provideOnRevisionFromEditCompleteQueuedTalkPageMessageJob
	 * @throws \JobQueueError
	 */
	public function testOnRevisionFromEditCompleteAutoModeratorSendRevertTalkPageMsgJobNotQueuedNotAutoModeratorUser(
		$wikiPage, $rev, $originalRevId, $user, $tags ) {
		$jobQueueGroup = $this->getServiceContainer()->getJobQueueGroup();
		$jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->delete();
		$wikiPageFactory = $this->createMock( WikiPageFactory::class );
		$wikiPageFactory->method( 'newFromID' )->willReturn( $wikiPage );
		$wikiConfig = $this->createMock( WikiPageConfig::class );
		$autoModWikiConfig = new AutoModeratorWikiConfigLoader(
			$wikiConfig,
			new HashConfig( [
				'DisableAnonTalk' => true,
				'AutoModeratorEnableWikiConfig' => true,
				'AutoModeratorEnableRevisionCheck' => true,
				'AutoModeratorUsername' => 'AutoModerator',
				'AutoModeratorRevertTalkPageMessageEnabled' => true,
				'AutoModeratorSkipUserRights' => [],
				'AutoModeratorFalsePositivePageTitle' => "",
			] )
		);
		$config = new HashConfig( [
			'DisableAnonTalk' => true,
			'AutoModeratorEnableWikiConfig' => true,
			'AutoModeratorEnableRevisionCheck' => true,
			'AutoModeratorUsername' => 'AutoModerator',
			'AutoModeratorWikiId' => "en"
		] );
		$userGroupManager = $this->createMock( UserGroupManager::class );
		$mockUser = $this->createMock( User::class );
		$mockRevisionStore = $this->createMock( RevisionStore::class );
		$mockRestrictionStore = $this->createMock( RestrictionStore::class );
		$mockRevisionStore->method( "getRevisionById" )->willReturn( $rev );
		$mockPermissionManager = $this->createMock( PermissionManager::class );

		( new RevisionFromEditCompleteHookHandler( $autoModWikiConfig, $userGroupManager,
			$config, $wikiPageFactory, $mockRevisionStore, $mockRestrictionStore, $jobQueueGroup,
			$mockPermissionManager ) )
			->onRevisionFromEditComplete( $wikiPage, $rev, $originalRevId,
				$mockUser, $tags );
		$this->assertFalse( $jobQueueGroup->get( 'AutoModeratorSendRevertTalkPageMsgJob' )->pop() );
	}
}
