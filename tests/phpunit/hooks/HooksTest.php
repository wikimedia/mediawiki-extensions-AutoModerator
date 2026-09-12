<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\AutoModerator\Tests\Hooks;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\AutoModerator\Hooks;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\AutoModerator\Hooks
 * @group AutoModerator
 * @group Database
 */
class HooksTest extends MediaWikiIntegrationTestCase {

	public function testOnHistoryToolsShows() {
		$services = $this->getServiceContainer();
		$jobQueueGroup = $services->getJobQueueGroup();
		$jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->delete();
		$config = new HashConfig( [
			'AutoModeratorEnableRevisionCheck' => true,
			'AutoModeratorMultilingualConfigEnableMultilingual' => false,
			'AutoModeratorUsername' => 'AutoModerator',
			'AutoModeratorSkipUserRights' => [],
			'AutoModeratorFalsePositivePageTitle' => 'Test False Positive',
			'AutoModeratorWikiId' => 'enwiki',
		] );

		$user = $this->createMock( User::class );
		// Make it match AutoMod user name
		$user->method( 'getName' )->willReturn( 'AutoModerator' );
		$revRecord = $this->createMock( RevisionRecord::class );
		$revRecord->method( 'getUser' )->willReturn( $user );
		$revRecord->method( 'getId' )->willReturn( 1000 );
		$mockUserIdentity = $this->createMock( UserIdentity::class );
		$mockTitle = $this->createMock( Title::class );
		$mockTitle->method( 'getFullURL' )->willReturn( 'test.url.com' );
		$mockTitleFactory = $this->createMock( TitleFactory::class );
		$mockTitleFactory->method( 'newFromText' )->willReturn( $mockTitle );

		$this->setUserLang( "qqx" );
		$links = [];
		( new Hooks( $config, $mockTitleFactory ) )->onHistoryTools(
			$revRecord,
			$links,
			null,
			$mockUserIdentity
		);

		$this->assertStringContainsString( 'automoderator-wiki-report-false-positive', $links[0] );
	}

	public function testOnHistoryToolsNoShow() {
		$services = $this->getServiceContainer();
		$jobQueueGroup = $services->getJobQueueGroup();
		$jobQueueGroup->get( 'AutoModeratorFetchRevScoreJob' )->delete();
		$config = new HashConfig( [
			'AutoModeratorEnableRevisionCheck' => true,
			'AutoModeratorMultilingualConfigEnableMultilingual' => false,
			'AutoModeratorUsername' => 'AutoModerator',
			'AutoModeratorSkipUserRights' => [],
			'AutoModeratorFalsePositivePageTitle' => null,
			'AutoModeratorWikiId' => 'enwiki',
		] );

		$user = $this->createMock( User::class );
		$revRecord = $this->createMock( RevisionRecord::class );
		$revRecord->method( 'getUser' )->willReturn( $user );
		$revRecord->method( 'getId' )->willReturn( 1000 );
		$mockUserIdentity = $this->createMock( UserIdentity::class );
		$mockTitleFactory = $this->createMock( TitleFactory::class );
		$mockTitleFactory->method( 'newFromText' )->willReturn( null );

		$this->setUserLang( "qqx" );
		$links = [];
		( new Hooks( $config, $mockTitleFactory ) )->onHistoryTools(
			$revRecord,
			$links,
			null,
			$mockUserIdentity
		);

		// Assert that a link was not added
		$this->assertSame( [], $links );
	}

}
