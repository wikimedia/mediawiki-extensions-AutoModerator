<?php

namespace AutoModerator\Tests\Hooks;

use AutoModerator\Config\AutoModeratorWikiConfigLoader;
use AutoModerator\Config\WikiPageConfig;
use AutoModerator\Hooks;
use AutoModerator\Util;
use MediaWiki\Config\HashConfig;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\User;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentity;
use MediaWikiIntegrationTestCase;

/**
 * @group AutoModerator
 * @group Database
 */
class HooksTest extends MediaWikiIntegrationTestCase {

	/**
	 * @covers AutoModerator\Hooks::onHistoryTools
	 */
	public function testOnHistoryToolsShows() {
		$services = $this->getServiceContainer();
		$jobQueueGroup = $services->getJobQueueGroup();
		$jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->delete();
		$wikiConfig = $this->createMock( WikiPageConfig::class );
		$wikiConfig->expects( $this->never() )->method( 'getWithFlags' );
		$autoModWikiConfig = new AutoModeratorWikiConfigLoader(
			$wikiConfig,
			new HashConfig( [
				'AutoModeratorEnableWikiConfig' => true,
				'AutoModeratorEnableRevisionCheck' => true,
				'AutoModeratorUsername' => 'AutoModerator',
				'AutoModeratorSkipUserRights' => [],
				'AutoModeratorFalsePositivePageTitle' => 'Test False Positive',
			] )
		);
		$config = new HashConfig( [
			'AutoModeratorEnableWikiConfig' => true,
			'AutoModeratorEnableRevisionCheck' => true,
			'AutoModeratorUsername' => 'AutoModerator',
			'AutoModeratorWikiId' => 'enwiki',
			'AutoModeratorFalsePositivePageTitle' => 'Test False Positive',
		] );
		$userGroupManager = $this->createMock( UserGroupManager::class );

		$user = $this->createMock( User::class );
		// Make it match AutoMod user ID
		$user->method( 'getId' )->willReturn( 1 );
		$revRecord = $this->createMock( RevisionRecord::class );
		$revRecord->method( 'getUser' )->willReturn( $user );
		$revRecord->method( 'getId' )->willReturn( 1000 );
		$mockUtil = $this->createMock( Util::class );
		$mockUtil->method( 'getAutoModeratorUser' )->willReturn( $user );
		$mockUserIdentity = $this->createMock( UserIdentity::class );
		$mockTitle = $this->createMock( Title::class );
		$mockTitle->method( 'getFullURL' )->willReturn( 'test.url.com' );
		$mockTitleFactory = $this->createMock( TitleFactory::class );
		$mockTitleFactory->method( 'newFromText' )->willReturn( $mockTitle );

		$this->setUserLang( "qqx" );
		$links = [];
		( new Hooks(
			$autoModWikiConfig, $userGroupManager, $config, $mockTitleFactory
			)
		)->onHistoryTools(
			$revRecord,
			$links,
			null,
			$mockUserIdentity
		);

		$this->assertStringContainsString( 'automoderator-wiki-report-false-positive', $links[0] );
	}

	/**
	 * @covers AutoModerator\Hooks::onHistoryTools
	 */
	public function testOnHistoryToolsNoShow() {
		$services = $this->getServiceContainer();
		$jobQueueGroup = $services->getJobQueueGroup();
		$jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->delete();
		$wikiConfig = $this->createMock( WikiPageConfig::class );
		$wikiConfig->expects( $this->never() )->method( 'getWithFlags' );
		$autoModWikiConfig = new AutoModeratorWikiConfigLoader(
			$wikiConfig,
			new HashConfig( [
				'AutoModeratorEnableWikiConfig' => true,
				'AutoModeratorEnableRevisionCheck' => true,
				'AutoModeratorUsername' => 'AutoModerator',
				'AutoModeratorSkipUserRights' => [],
				'AutoModeratorFalsePositivePageTitle' => null,
			] )
		);
		$config = new HashConfig( [
			'AutoModeratorEnableWikiConfig' => true,
			'AutoModeratorEnableRevisionCheck' => true,
			'AutoModeratorUsername' => 'AutoModerator',
			'AutoModeratorWikiId' => 'enwiki',
			'AutoModeratorFalsePositivePageTitle' => null,
		] );
		$userGroupManager = $this->createMock( UserGroupManager::class );

		$user = $this->createMock( User::class );
		$revRecord = $this->createMock( RevisionRecord::class );
		$revRecord->method( 'getUser' )->willReturn( $user );
		$revRecord->method( 'getId' )->willReturn( 1000 );
		$mockUtil = $this->createMock( Util::class );
		$mockUtil->method( 'getAutoModeratorUser' )->willReturn( $this->createMock( User::class ) );
		$mockUserIdentity = $this->createMock( UserIdentity::class );
		$mockTitleFactory = $this->createMock( TitleFactory::class );
		$mockTitleFactory->method( 'newFromText' )->willReturn( null );

		$this->setUserLang( "qqx" );
		$links = [];
		( new Hooks(
			$autoModWikiConfig, $userGroupManager, $config, $mockTitleFactory
			)
		)->onHistoryTools(
			$revRecord,
			$links,
			null,
			$mockUserIdentity
		);

		// Assert that a link was not added
		$this->assertSame( [], $links );
	}

}
