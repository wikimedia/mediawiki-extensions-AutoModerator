<?php

namespace AutoModerator\Tests\Hooks;

use AutoModerator\Config\AutoModeratorWikiConfigLoader;
use AutoModerator\Config\WikiPageConfig;
use AutoModerator\Hooks;
use AutoModerator\Util;
use MediaWiki\Config\HashConfig;
use MediaWiki\Page\WikiPageFactory;
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

	public function provideOnRevisionFromEditCompleteQueued() {
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

	/**
	 * @dataProvider provideOnRevisionFromEditCompleteQueued
	 */
	public function testOnRevisionFromEditCompleteQueued( $wikiPage, $rev, $originalRevId, $user, $tags ) {
		$jobQueueGroup = $this->getServiceContainer()->getJobQueueGroup();
		$contentHandlerFactory = $this->getServiceContainer()->getContentHandlerFactory();
		$jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->delete();
		$wikiConfig = $this->createMock( WikiPageConfig::class );
		$wikiConfig->expects( $this->never() )->method( 'getWithFlags' );
		$autoModWikiConfig = new AutoModeratorWikiConfigLoader(
			$wikiConfig,
			new HashConfig( [
				'AutoModeratorEnableWikiConfig' => true,
				'AutoModeratorEnableRevisionCheck' => true,
				'AutoModeratorUsername' => 'AutoModerator',
				'AutoModeratorSkipUserGroups' => [],
			] )
		);
		$config = new HashConfig( [
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

		( new Hooks( $autoModWikiConfig, $userGroupManager,
			$config, $wikiPageFactory, $mockRevisionStore,
			$contentHandlerFactory, $mockRestrictionStore ) )
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
			'requestId' => 42
		];
		$this->assertEquals( $expected, $actual );
	}

	public function provideOnRevisionFromEditCompleteNotQueued() {
		$wikiPageMain = $this->createMock( WikiPage::class );
		$wikiPageMain->method( 'getNamespace' )->willReturn( NS_MAIN );
		$wikiPageTalk = $this->createMock( WikiPage::class );
		$wikiPageTalk->method( 'getNamespace' )->willReturn( NS_TALK );
		return [
			[ null, $this->createMock( RevisionRecord::class ), false, $this->createMock( UserIdentity::class ), [] ],
			[ $wikiPageMain, null, false, $this->createMock( UserIdentity::class ), [] ],
			[ $wikiPageMain, $this->createMock( RevisionRecord::class ), false, null, [] ],
			[
				$wikiPageTalk,
				$this->createMock( RevisionRecord::class ),
				false,
				$this->createMock( UserIdentity::class ),
				[]
			]
		];
	}

	/**
	 * @dataProvider provideOnRevisionFromEditCompleteNotQueued
	 */
	public function testOnRevisionFromEditCompleteNotQueued( $wikiPage, $rev, $originalRevId, $user, $tags ) {
		$jobQueueGroup = $this->getServiceContainer()->getJobQueueGroup();
		$jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->delete();
		$wikiPageFactory = $this->getServiceContainer()->getWikiPageFactory();
		$contentHandlerFactory = $this->getServiceContainer()->getContentHandlerFactory();
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

		( new Hooks( $autoModWikiConfig, $userGroupManager,
			$config, $wikiPageFactory, $mockRevisionStore,
			$contentHandlerFactory, $mockRestrictionStore ) )
			->onRevisionFromEditComplete( $wikiPage, $rev, $originalRevId, $user, $tags );

		$this->assertFalse( $jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->pop() );
	}
}
