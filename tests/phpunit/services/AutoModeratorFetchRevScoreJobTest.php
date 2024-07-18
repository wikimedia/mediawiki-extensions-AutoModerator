<?php

namespace AutoModerator\Tests;

use AutoModerator\Services\AutoModeratorFetchRevScoreJob;
use MockHttpTrait;

/**
 * @group AutoModerator
 * @group Database
 * @covers AutoModerator\Services\AutoModeratorFetchRevScoreJob
 */
class AutoModeratorFetchRevScoreJobTest extends \MediaWikiIntegrationTestCase {

	use MockHttpTrait;

	/**
	 * @return array
	 */
	private function createTestPage(): array {
		$wikiPage = $this->insertPage( 'TestJob', 'Test text' );
		$user = $this->getTestUser()->getUserIdentity();
		$this->editPage( $this->getExistingTestPage( $wikiPage['title'] ), 'Content' );
		$revisionStore = $this->getServiceContainer()->getRevisionStore();
		$rev = $revisionStore->getRevisionByPageId( $wikiPage['id'] );
		$title = $wikiPage['title'];
		return [ $wikiPage, $user, $rev, $title ];
	}

	protected function setUp(): void {
		parent::setUp();
	}

	/**
	 * @covers AutoModerator\Services\AutoModeratorFetchRevScoreJob::run
	 * @group Database
	 */
	public function testRunSuccess() {
		[ $wikiPage, $user, $rev, $title ] = $this->createTestPage();

		$score = [
			'model_name' => 'revertrisk-language-agnostic',
			'model_version' => '3',
			'wiki_db' => 'enwiki',
			'revision_id' => $rev->getId(),
			'output' => [
				'prediction' => true,
				'probabilities' => [
					'true' => 0.9987422,
					'false' => 0.00012578,
				],
			],
		];

		$this->installMockHttp( $this->makeFakeHttpRequest( json_encode( $score ) ) );

		$job = new AutoModeratorFetchRevScoreJob( $title,
			[
				'wikiPageId' => $wikiPage[ 'id' ],
				'revId' => $rev->getId(),
				'originalRevId' => false,
				'userId' => $user->getId(),
				'userName' => $user->getName(),
				'tags' => [],
				'undoSummary' => "undoSummary"
			]
		);

		$success = $job->run();

		$this->assertTrue( $success );
	}

	/**
	 * @covers AutoModerator\Services\AutoModeratorFetchRevScoreJob::run
	 * @group Database
	 */
	public function testRunSuccessWithMinorEditFlagTrue() {
		[ $wikiPage, $user, $rev, $title ] = $this->createTestPage();
		$this->overrideConfigValue( 'AutoModeratorUseEditFlagMinor', true );
		$score = [
			'model_name' => 'revertrisk-language-agnostic',
			'model_version' => '3',
			'wiki_db' => 'enwiki',
			'revision_id' => $rev->getId(),
			'output' => [
				'prediction' => true,
				'probabilities' => [
					'true' => 0.9987422,
					'false' => 0.00012578,
				],
			],
		];

		$this->installMockHttp( $this->makeFakeHttpRequest( json_encode( $score ) ) );

		$job = new AutoModeratorFetchRevScoreJob( $title,
			[
				'wikiPageId' => $wikiPage[ 'id' ],
				'revId' => $rev->getId(),
				'originalRevId' => false,
				'userId' => $user->getId(),
				'userName' => $user->getName(),
				'tags' => [],
				'undoSummary' => "undoSummary"
			]
		);

		$success = $job->run();

		$this->assertTrue( $success );
		$newPage = $this->getExistingTestPage( $wikiPage['title'] );
		$newRevisionRecord = $newPage->getRevisionRecord();
		$this->assertTrue( $newRevisionRecord->isMinor() );
	}

	/**
	 * @covers AutoModerator\Services\AutoModeratorFetchRevScoreJob::run
	 * @group Database
	 */
	public function testRunSuccessWithMinorEditFlagFalse() {
		[ $wikiPage, $user, $rev, $title ] = $this->createTestPage();
		$this->overrideConfigValue( 'AutoModeratorUseEditFlagMinor', false );
		$score = [
			'model_name' => 'revertrisk-language-agnostic',
			'model_version' => '3',
			'wiki_db' => 'enwiki',
			'revision_id' => $rev->getId(),
			'output' => [
				'prediction' => true,
				'probabilities' => [
					'true' => 0.9987422,
					'false' => 0.00012578,
				],
			],
		];

		$this->installMockHttp( $this->makeFakeHttpRequest( json_encode( $score ) ) );

		$job = new AutoModeratorFetchRevScoreJob( $title,
			[
				'wikiPageId' => $wikiPage[ 'id' ],
				'revId' => $rev->getId(),
				'originalRevId' => false,
				'userId' => $user->getId(),
				'userName' => $user->getName(),
				'tags' => [],
				'undoSummary' => "undoSummary"
			]
		);

		$success = $job->run();

		$this->assertTrue( $success );
		$newPage = $this->getExistingTestPage( $wikiPage['title'] );
		$newRevisionRecord = $newPage->getRevisionRecord();
		$this->assertFalse( $newRevisionRecord->isMinor() );
	}

	/**
	 * @covers AutoModerator\Services\AutoModeratorFetchRevScoreJob::run
	 * @group Database
	 */
	public function testRunSuccessWithBotFlagTrue() {
		[ $wikiPage, $user, $rev, $title ] = $this->createTestPage();
		$this->overrideConfigValue( 'AutoModeratorEnableBotFlag', true );
		$score = [
			'model_name' => 'revertrisk-language-agnostic',
			'model_version' => '3',
			'wiki_db' => 'enwiki',
			'revision_id' => $rev->getId(),
			'output' => [
				'prediction' => true,
				'probabilities' => [
					'true' => 0.9987422,
					'false' => 0.00012578,
				],
			],
		];

		$this->installMockHttp( $this->makeFakeHttpRequest( json_encode( $score ) ) );

		$job = new AutoModeratorFetchRevScoreJob( $title,
			[
				'wikiPageId' => $wikiPage[ 'id' ],
				'revId' => $rev->getId(),
				'originalRevId' => false,
				'userId' => $user->getId(),
				'userName' => $user->getName(),
				'tags' => [],
				'undoSummary' => "undoSummary"
			]
		);
		$success = $job->run();

		$newPage = $this->getExistingTestPage( $wikiPage['title'] );
		$newRevisionRecord = $newPage->getRevisionRecord();
		$isBotChange = $this->db->newSelectQueryBuilder()->select( 'rc_bot' )
			->from( 'recentchanges' )
			->where( $this->db->expr( 'rc_this_oldid', '=', $newRevisionRecord->getId() ) )->fetchRow();
		$this->assertTrue( $success );
		$this->assertSame( '1', $isBotChange->rc_bot );
	}

	/**
	 * @covers AutoModerator\Services\AutoModeratorFetchRevScoreJob::run
	 * @group Database
	 */
	public function testRunSuccessWithBotFlagFalse() {
		[ $wikiPage, $user, $rev, $title ] = $this->createTestPage();
		$this->overrideConfigValue( 'AutoModeratorEnableBotFlag', false );
		$score = [
			'model_name' => 'revertrisk-language-agnostic',
			'model_version' => '3',
			'wiki_db' => 'enwiki',
			'revision_id' => $rev->getId(),
			'output' => [
				'prediction' => true,
				'probabilities' => [
					'true' => 0.9987422,
					'false' => 0.00012578,
				],
			],
		];

		$this->installMockHttp( $this->makeFakeHttpRequest( json_encode( $score ) ) );

		$job = new AutoModeratorFetchRevScoreJob( $title,
			[
				'wikiPageId' => $wikiPage[ 'id' ],
				'revId' => $rev->getId(),
				'originalRevId' => false,
				'userId' => $user->getId(),
				'userName' => $user->getName(),
				'tags' => [],
				'undoSummary' => "undoSummary"
			]
		);
		$success = $job->run();

		$newPage = $this->getExistingTestPage( $wikiPage['title'] );
		$newRevisionRecord = $newPage->getRevisionRecord();
		$isBotChange = $this->db->newSelectQueryBuilder()->select( 'rc_bot' )
			->from( 'recentchanges' )
			->where( $this->db->expr( 'rc_this_oldid', '=', $newRevisionRecord->getId() ) )->fetchRow();
		$this->assertTrue( $success );
		$this->assertSame( '0', $isBotChange->rc_bot );
	}

	/**
	 * @covers AutoModerator\Services\AutoModeratorFetchRevScoreJob::run
	 */
	public function testRunSuccessManualRevert() {
		$wikiPage = $this->insertPage( 'TestJob', 'Test text' );
		$user = $this->getTestUser()->getUserIdentity();
		$this->editPage( $this->getExistingTestPage( $wikiPage[ 'title' ] ), 'Content' );
		$revisionStore = $this->getServiceContainer()->getRevisionStore();
		$rev = $revisionStore->getRevisionByPageId( $wikiPage[ 'id' ] );
		// Add more edits so that $rev is not the most recent revision, causing a revert conflict
		$this->editPage( $this->getExistingTestPage( $wikiPage[ 'title' ] ), 'Content33' );
		$this->editPage( $this->getExistingTestPage( $wikiPage[ 'title' ] ), 'Content44' );
		$title = $wikiPage[ 'title' ];

		$score = [
			'model_name' => 'revertrisk-language-agnostic',
			'model_version' => '3',
			'wiki_db' => 'enwiki',
			'revision_id' => $rev->getId(),
			'output' => [
				'prediction' => true,
				'probabilities' => [
					'true' => 0.9987422,
					'false' => 0.00012578,
				],
			],
		];
		$this->installMockHttp( $this->makeFakeHttpRequest( json_encode( $score ) ) );

		$job = new AutoModeratorFetchRevScoreJob( $title,
			[
				'wikiPageId' => $wikiPage[ 'id' ],
				'revId' => $rev->getId(),
				'originalRevId' => false,
				'userId' => $user->getId(),
				'userName' => $user->getName(),
				'tags' => [],
				'undoSummary' => "undoSummary"
			]
		);

		$success = $job->run();
		$this->assertNotEmpty( $job->getLastError() );
		$this->assertFalse( $success );
	}

	/**
	 * @covers AutoModerator\Services\AutoModeratorFetchRevScoreJob::run
	 * when there is a bad request response returns false
	 */
	public function testRunWithBadRequestReturnsFailure() {
		[ $wikiPage, $user, $rev, $title ] = $this->createTestPage();

		$score = [];

		$this->installMockHttp( $this->makeFakeHttpRequest( json_encode( $score ), 400 ) );

		$job = new AutoModeratorFetchRevScoreJob( $title,
			[
				'wikiPageId' => $wikiPage[ 'id' ],
				'revId' => $rev->getId(),
				'originalRevId' => false,
				'userId' => $user->getId(),
				'userName' => $user->getName(),
				'tags' => [],
				'undoSummary' => "undoSummary"
			]
		);

		$success = $job->run();

		$this->assertFalse( $success );
	}

	/**
	 * @covers AutoModerator\Services\AutoModeratorFetchRevScoreJob::run
	 * when there is an unexpected 5xx response returns false
	 */
	public function testRunWithUnexpectedExceptionReturnsFalse() {
		[ $wikiPage, $user, $rev, $title ] = $this->createTestPage();

		$score = [];

		$this->installMockHttp( $this->makeFakeHttpRequest( json_encode( $score ), 500 ) );

		$job = new AutoModeratorFetchRevScoreJob( $title,
			[
				'wikiPageId' => $wikiPage[ 'id' ],
				'revId' => $rev->getId(),
				'originalRevId' => false,
				'userId' => $user->getId(),
				'userName' => $user->getName(),
				'tags' => [],
				'undoSummary' => "undoSummary"
			]
		);

		$success = $job->run();

		$this->assertFalse( $success );
	}

	/**
	 * @covers AutoModerator\Services\AutoModeratorFetchRevScoreJob::run
	 * when there is a server timeout 504 response returns false
	 */
	public function testRunWithServerTimeoutReturnsFalse() {
		[ $wikiPage, $user, $rev, $title ] = $this->createTestPage();

		$score = [];

		$this->installMockHttp( $this->makeFakeHttpRequest( json_encode( $score ), 504 ) );

		$job = new AutoModeratorFetchRevScoreJob( $title,
			[
				'wikiPageId' => $wikiPage[ 'id' ],
				'revId' => $rev->getId(),
				'originalRevId' => false,
				'userId' => $user->getId(),
				'userName' => $user->getName(),
				'tags' => [],
				'undoSummary' => "undoSummary"
			]
		);

		$success = $job->run();

		$this->assertFalse( $success );
	}

	/**
	 * @covers AutoModerator\Services\AutoModeratorFetchRevScoreJob::run
	 * when the revision lookup fails
	 */
	public function testRunWithBadRevisionId() {
		[ $wikiPage, $user, $rev, $title ] = $this->createTestPage();

		$score = [
			'model_name' => 'revertrisk-language-agnostic',
			'model_version' => '3',
			'wiki_db' => 'enwiki',
			'revision_id' => $rev->getId(),
			'output' => [
				'prediction' => true,
				'probabilities' => [
					'true' => 0.9987422,
					'false' => 0.00012578,
				],
			],
		];

		$this->installMockHttp( $this->makeFakeHttpRequest( json_encode( $score ) ) );

		$job = new AutoModeratorFetchRevScoreJob( $title,
			[
				'wikiPageId' => $wikiPage[ 'id' ],
				'revId' => 9999999999,
				'originalRevId' => false,
				'userId' => $user->getId(),
				'userName' => $user->getName(),
				'tags' => [],
				'undoSummary' => "undoSummary"
			]
		);

		$success = $job->run();

		$this->assertFalse( $success );
	}
}
