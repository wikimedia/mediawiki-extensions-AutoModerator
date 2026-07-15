<?php

namespace MediaWiki\Extension\AutoModerator\Tests;

use MediaWiki\Extension\AutoModerator\AutoModeratorRevisionStore;

/**
 * @group AutoModerator
 * @group Database
 * @covers \MediaWiki\Extension\AutoModerator\AutoModeratorRevisionStore
 */
class AutoModeratorRevisionStoreTest extends \MediaWikiIntegrationTestCase {

	public function testGetAutoModeratorReverts() {
		$db = $this->getServiceContainer()->getConnectionProvider()->getPrimaryDatabase();
		$revisionStore = $this->getServiceContainer()->getRevisionStore();
		$user = $this->getTestUser();
		$autoModeratorUser = $this->getTestUser( [ 'bot' ] );
		$wikiPage = $this->insertPage( 'TestJob', 'Test text',
			NS_MAIN, $autoModeratorUser->getUser() );
		$this->editPage( $this->getExistingTestPage( $wikiPage['title'] ),
			'Content1', '', NS_MAIN, $user->getAuthority() );
		$this->editPage( $this->getExistingTestPage( $wikiPage['title'] ),
			'Content2', '', NS_MAIN, $autoModeratorUser->getAuthority() );

		$autoModeratorRevisionStore = new AutoModeratorRevisionStore(
			$db, $autoModeratorUser->getUser(), $user->getUser(), $wikiPage['id'], $revisionStore, 1 );
		$result = $autoModeratorRevisionStore->getAutoModeratorReverts();

		$this->assertSame( 1, $result->count() );
	}

	public function testGetAutoModeratorRevertsMultipleReverts() {
		$db = $this->getServiceContainer()->getConnectionProvider()->getPrimaryDatabase();
		$revisionStore = $this->getServiceContainer()->getRevisionStore();
		$user = $this->getTestUser();
		$autoModeratorUser = $this->getTestUser( [ 'bot' ] );
		$wikiPage = $this->insertPage( 'TestJob1',
			'Test text', NS_MAIN, $autoModeratorUser->getUser() );
		$this->editPage( $this->getExistingTestPage( $wikiPage['title'] ),
			'Content1', '', NS_MAIN, $user->getAuthority() );
		$this->editPage( $this->getExistingTestPage( $wikiPage['title'] ),
			'Content2', '', NS_MAIN, $autoModeratorUser->getAuthority() );
		$this->editPage( $this->getExistingTestPage( $wikiPage['title'] ),
			'Content1', '', NS_MAIN, $user->getAuthority() );
		$this->editPage( $this->getExistingTestPage( $wikiPage['title'] ),
			'Content2', '', NS_MAIN, $autoModeratorUser->getAuthority() );
		$this->editPage( $this->getExistingTestPage( $wikiPage['title'] ),
			'Content3', '', NS_MAIN, $user->getAuthority() );
		$this->editPage( $this->getExistingTestPage( $wikiPage['title'] ),
			'Content4', '', NS_MAIN, $autoModeratorUser->getAuthority() );

		$autoModeratorRevisionStore = new AutoModeratorRevisionStore(
			$db, $autoModeratorUser->getUser(), $user->getUser(), $wikiPage['id'], $revisionStore, 1 );
		$result = $autoModeratorRevisionStore->getAutoModeratorReverts();

		$this->assertSame( 3, $result->count() );
	}

	public function testGetAutoModeratorRevertsNoReverts() {
		$db = $this->getServiceContainer()->getConnectionProvider()->getPrimaryDatabase();
		$revisionStore = $this->getServiceContainer()->getRevisionStore();
		$user = $this->getTestUser();
		$autoModeratorUser = $this->getTestUser( [ 'bot' ] );
		$wikiPage = $this->insertPage( 'TestJob', 'Test text',
			NS_MAIN, $this->getTestUser()->getUser() );
		$this->editPage( $this->getExistingTestPage( $wikiPage['title'] ),
			'Content2', '', NS_MAIN, $user->getAuthority() );
		$this->editPage( $this->getExistingTestPage( $wikiPage['title'] ),
			'Content3', '', NS_MAIN, $user->getAuthority() );

		$autoModeratorRevisionStore = new AutoModeratorRevisionStore(
			$db, $user->getUser(), $autoModeratorUser->getUser(), $wikiPage['id'], $revisionStore, 1 );
		$result = $autoModeratorRevisionStore->getAutoModeratorReverts();

		$this->assertSame( 0, $result->count() );
	}

	public function testHasReachedMaxRevertsForUser() {
		$db = $this->getServiceContainer()->getConnectionProvider()->getPrimaryDatabase();
		$revisionStore = $this->getServiceContainer()->getRevisionStore();
		$user = $this->getTestUser();
		$autoModeratorUser = $this->getTestUser( [ 'bot' ] );
		$wikiPage = $this->insertPage( 'TestJob3', 'Test text',
			NS_MAIN, $autoModeratorUser->getUser() );
		$this->editPage( $this->getExistingTestPage( $wikiPage['title'] ),
			'Content1', '', NS_MAIN, $user->getAuthority() );
		$this->editPage( $this->getExistingTestPage( $wikiPage['title'] ),
			'Content2', '', NS_MAIN, $autoModeratorUser->getAuthority() );

		$autoModeratorRevisionStore = new AutoModeratorRevisionStore(
			$db, $autoModeratorUser->getUser(), $user->getUser(), $wikiPage['id'], $revisionStore, 1 );
		$result = $autoModeratorRevisionStore->hasReachedMaxRevertsForUser();

		$this->assertTrue( $result );
	}

	public function testHasReachedMaxRevertsForUserMoreThanOne() {
		$db = $this->getServiceContainer()->getConnectionProvider()->getPrimaryDatabase();
		$revisionStore = $this->getServiceContainer()->getRevisionStore();
		$user = $this->getTestUser();
		$autoModeratorUser = $this->getTestUser( [ 'bot' ] );
		$wikiPage = $this->insertPage( 'TestJob4',
			'Test text', NS_MAIN, $autoModeratorUser->getUser() );
		$this->editPage( $this->getExistingTestPage( $wikiPage['title'] ),
			'Content1', '', NS_MAIN, $user->getAuthority() );
		$this->editPage( $this->getExistingTestPage( $wikiPage['title'] ),
			'Content2', '', NS_MAIN, $autoModeratorUser->getAuthority() );
		$this->editPage( $this->getExistingTestPage( $wikiPage['title'] ),
			'Content3', '', NS_MAIN, $user->getAuthority() );
		$this->editPage( $this->getExistingTestPage( $wikiPage['title'] ),
			'Content4', '', NS_MAIN, $autoModeratorUser->getAuthority() );

		$autoModeratorRevisionStore = new AutoModeratorRevisionStore(
			$db, $autoModeratorUser->getUser(), $user->getUser(), $wikiPage['id'], $revisionStore, 1 );
		$result = $autoModeratorRevisionStore->hasReachedMaxRevertsForUser();

		$this->assertTrue( $result );
	}

	public function testHasReachedMaxRevertsFalseWhenThereAreNoReverts() {
		$db = $this->getServiceContainer()->getConnectionProvider()->getPrimaryDatabase();
		$revisionStore = $this->getServiceContainer()->getRevisionStore();
		$user = $this->getTestUser();
		$autoModeratorUser = $this->getTestUser( [ 'bot' ] );
		$wikiPage = $this->insertPage( 'TestJob5', 'Test text',
			NS_MAIN, $this->getTestUser()->getUser() );
		$this->editPage( $this->getExistingTestPage( $wikiPage['title'] ),
			'Content1', '', NS_MAIN, $user->getAuthority() );
		$this->editPage( $this->getExistingTestPage( $wikiPage['title'] ),
			'Content2', '', NS_MAIN, $user->getAuthority() );
		$this->editPage( $this->getExistingTestPage( $wikiPage['title'] ),
			'Content3', '', NS_MAIN, $user->getAuthority() );

		$autoModeratorRevisionStore = new AutoModeratorRevisionStore(
			$db, $user->getUser(), $autoModeratorUser->getUser(), $wikiPage['id'], $revisionStore, 1 );
		$result = $autoModeratorRevisionStore->hasReachedMaxRevertsForUser();

		$this->assertFalse( $result );
	}

}
