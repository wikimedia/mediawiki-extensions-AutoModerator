<?php

namespace AutoModerator\Tests;

use AutoModerator\Services\AutoModeratorFetchRevScoreJob;
use MockHttpTrait;
use RuntimeException;

/**
 * @group AutoModerator
 * @group Database
 * @covers AutoModerator\Services\AutoModeratorFetchRevScoreJob
 */
class AutoModeratorFetchRevScoreJobTest extends \MediaWikiIntegrationTestCase {

	use MockHttpTrait;

	protected function setUp(): void {
		parent::setUp();
	}

	/**
	 * @covers AutoModerator\Services\AutoModeratorFetchRevScoreJob::run
	 * @group Database
	 */
	public function testRunSuccess() {
		$wikiPage = $this->insertPage( 'TestJob', 'Test text' );
		$user = $this->getTestUser()->getUserIdentity();
		$this->editPage( $this->getExistingTestPage( $wikiPage[ 'title' ] ), 'Content' );
		$revisionStore = $this->getServiceContainer()->getRevisionStore();
		$rev = $revisionStore->getRevisionByPageId( $wikiPage[ 'id' ] );
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
				'user' => $user,
				'tags' => []
			]
		);

		$success = $job->run();

		$this->assertTrue( $success );
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
				'user' => $user,
				'tags' => []
			]
		);

		$expected = 'Revision ' . $rev->getId() . ' requires a manual revert.';
		try {
			$job->run();
			$this->fail( 'Exception expected but not thrown' );
		} catch ( RunTimeException $e ) {
			$message = $e->getMessage();
			$this->assertEquals( $expected, $message );
		}
	}

}
