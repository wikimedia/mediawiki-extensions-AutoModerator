<?php

namespace AutoModerator\Tests\Hooks;

use AutoModerator\Config\AutoModeratorWikiConfigLoader;
use AutoModerator\Config\WikiPageConfig;
use AutoModerator\Hooks;
use AutoModerator\Util;
use IDBAccessObject;
use MediaWiki\Config\HashConfig;
use MediaWiki\Revision\RevisionRecord;
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
		$wikiPage->method( 'getTitle' )->willReturn( $this->createMock( Title::class ) );
		$rev = $this->createMock( RevisionRecord::class );
		$rev->method( 'getId' )->willReturn( 1000 );
		$user = $this->createMock( UserIdentity::class );
		return [
			[ $wikiPage, $rev, false, $user, [] ]
		];
	}

	/**
	 * @dataProvider provideOnRevisionFromEditCompleteQueued
	 */
	public function testOnRevisionFromEditCompleteQueued( $wikiPage, $rev, $originalRevId, $user, $tags ) {
		$jobQueueGroup = $this->getServiceContainer()->getJobQueueGroup();
		$jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->delete();
		$wikiConfig = $this->createMock( WikiPageConfig::class );
		$wikiConfig->expects( $this->once() )->method( 'hasWithFlags' )
			->with( 'AutoModeratorEnableRevisionCheck', IDBAccessObject::READ_NORMAL )
			->willReturn( false );
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

		( new Hooks( $autoModWikiConfig, $userGroupManager, $config ) )
			->onRevisionFromEditComplete( $wikiPage, $rev, $originalRevId, $user, $tags );

		$actual = $jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->pop()->getParams();
		$actual[ 'requestId' ] = 42;
		$expected = [
			'wikiPageId' => 1,
			'revId' => 1000,
			'originalRevId' => false,
			'user' => $user,
			'tags' => [],
			'namespace' => NS_MAIN,
			'title' => '',
			'requestId' => 42
		];
		$this->assertEquals( $expected, $actual );
	}

	public function provideOnRevisionFromEditCompleteNotQueued() {
		return [
			[ null, $this->createMock( RevisionRecord::class ), false, $this->createMock( UserIdentity::class ), [] ],
			[ $this->createMock( WikiPage::class ), null, false, $this->createMock( UserIdentity::class ), [] ],
			[ $this->createMock( WikiPage::class ), $this->createMock( RevisionRecord::class ), false, null, [] ]
		];
	}

	/**
	 * @dataProvider provideOnRevisionFromEditCompleteNotQueued
	 */
	public function testOnRevisionFromEditCompleteNotQueued( $wikiPage, $rev, $originalRevId, $user, $tags ) {
		$jobQueueGroup = $this->getServiceContainer()->getJobQueueGroup();
		$jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->delete();
		$wikiConfig = $this->createMock( WikiPageConfig::class );
		$wikiConfig->expects( $this->once() )->method( 'hasWithFlags' )
			->with( 'AutoModeratorEnableRevisionCheck', IDBAccessObject::READ_NORMAL )
			->willReturn( false );
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

		( new Hooks( $autoModWikiConfig, $userGroupManager, $config ) )
			->onRevisionFromEditComplete( $wikiPage, $rev, $originalRevId, $user, $tags );

		$this->assertFalse( $jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->pop() );
	}
}
