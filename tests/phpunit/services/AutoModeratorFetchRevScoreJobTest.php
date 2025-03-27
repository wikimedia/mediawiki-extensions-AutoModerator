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
		$this->overrideConfigValue( 'OresModels', [
			'revertrisklanguageagnostic' => [ 'enabled' => false, 'namespaces' => [ 0 ] ]
		] );
		$this->overrideConfigValue( 'AutoModeratorMultiLingualRevertRisk', null );
		$this->overrideConfigValue( 'AutoModeratorEnableMultilingual', false );

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
				'wikiPageId' => $wikiPage['id'],
				'revId' => $rev->getId(),
				'originalRevId' => false,
				'userId' => $user->getId(),
				'userName' => $user->getName(),
				'tags' => [],
				'scores' => null,
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
		$this->overrideConfigValue( 'OresModels', [
			'revertrisklanguageagnostic' => [ 'enabled' => false, 'namespaces' => [ 0 ] ]
		] );
		$this->overrideConfigValue( 'AutoModeratorMultiLingualRevertRisk', null );
		$this->overrideConfigValue( 'AutoModeratorEnableMultilingual', false );

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
				'wikiPageId' => $wikiPage['id'],
				'revId' => $rev->getId(),
				'originalRevId' => false,
				'userId' => $user->getId(),
				'userName' => $user->getName(),
				'tags' => [],
				'scores' => null,
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
		$this->overrideConfigValue( 'OresModels', [
			'revertrisklanguageagnostic' => [ 'enabled' => false, 'namespaces' => [ 0 ] ]
		] );
		$this->overrideConfigValue( 'AutoModeratorMultiLingualRevertRisk', null );
		$this->overrideConfigValue( 'AutoModeratorEnableMultilingual', false );

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
				'wikiPageId' => $wikiPage['id'],
				'revId' => $rev->getId(),
				'originalRevId' => false,
				'userId' => $user->getId(),
				'userName' => $user->getName(),
				'tags' => [],
				'scores' => null,
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
		$this->overrideConfigValue( 'OresModels', [
			'revertrisklanguageagnostic' => [ 'enabled' => false, 'namespaces' => [ 0 ] ]
		] );
		$this->overrideConfigValue( 'AutoModeratorMultiLingualRevertRisk', null );
		$this->overrideConfigValue( 'AutoModeratorEnableMultilingual', false );

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
				'wikiPageId' => $wikiPage['id'],
				'revId' => $rev->getId(),
				'originalRevId' => false,
				'userId' => $user->getId(),
				'userName' => $user->getName(),
				'tags' => [],
				'scores' => null,
			]
		);
		$success = $job->run();

		$newPage = $this->getExistingTestPage( $wikiPage['title'] );
		$newRevisionRecord = $newPage->getRevisionRecord();
		$isBotChange = $this->getDb()->newSelectQueryBuilder()->select( 'rc_bot' )
			->from( 'recentchanges' )
			->where( $this->getDb()->expr( 'rc_this_oldid', '=', $newRevisionRecord->getId() ) )->fetchRow();
		$this->assertTrue( $success );
		$this->assertSame( '1', $isBotChange->rc_bot );
	}

	/**
	 * @covers AutoModerator\Services\AutoModeratorFetchRevScoreJob::run
	 * @group Database
	 */
	public function testRunSuccessWithBotFlagFalse() {
		$this->overrideConfigValue( 'OresModels', [
			'revertrisklanguageagnostic' => [ 'enabled' => false, 'namespaces' => [ 0 ] ]
		] );
		[ $wikiPage, $user, $rev, $title ] = $this->createTestPage();
		$this->overrideConfigValue( 'AutoModeratorEnableBotFlag', false );
		$this->overrideConfigValue( 'AutoModeratorMultiLingualRevertRisk', null );
		$this->overrideConfigValue( 'AutoModeratorEnableMultilingual', false );

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
				'wikiPageId' => $wikiPage['id'],
				'revId' => $rev->getId(),
				'originalRevId' => false,
				'userId' => $user->getId(),
				'userName' => $user->getName(),
				'tags' => [],
				'scores' => null,
			]
		);
		$success = $job->run();

		$newPage = $this->getExistingTestPage( $wikiPage['title'] );
		$newRevisionRecord = $newPage->getRevisionRecord();
		$isBotChange = $this->getDb()->newSelectQueryBuilder()->select( 'rc_bot' )
			->from( 'recentchanges' )
			->where( $this->getDb()->expr( 'rc_this_oldid', '=', $newRevisionRecord->getId() ) )->fetchRow();
		$this->assertTrue( $success );
		$this->assertSame( '0', $isBotChange->rc_bot );
	}

	/**
	 * @covers AutoModerator\Services\AutoModeratorFetchRevScoreJob::run
	 */
	public function testRunSuccessManualRevert() {
		$this->overrideConfigValue( 'OresModels', [
			'revertrisklanguageagnostic' => [ 'enabled' => false, 'namespaces' => [ 0 ] ]
		] );
		$this->overrideConfigValue( 'AutoModeratorMultiLingualRevertRisk', null );
		$this->overrideConfigValue( 'AutoModeratorEnableMultilingual', false );

		$wikiPage = $this->insertPage( 'TestJob', 'Test text' );
		$user = $this->getTestUser()->getUserIdentity();
		$this->editPage( $this->getExistingTestPage( $wikiPage['title'] ), 'Content' );
		$revisionStore = $this->getServiceContainer()->getRevisionStore();
		$rev = $revisionStore->getRevisionByPageId( $wikiPage['id'] );
		// Add more edits so that $rev is not the most recent revision, causing a revert conflict
		$this->editPage( $this->getExistingTestPage( $wikiPage['title'] ), 'Content33' );
		$this->editPage( $this->getExistingTestPage( $wikiPage['title'] ), 'Content44' );
		$title = $wikiPage['title'];

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
				'wikiPageId' => $wikiPage['id'],
				'revId' => $rev->getId(),
				'originalRevId' => false,
				'userId' => $user->getId(),
				'userName' => $user->getName(),
				'tags' => [],
				'scores' => null,
			]
		);

		$success = $job->run();
		$this->assertTrue( $success );
	}

	/**
	 * @covers AutoModerator\Services\AutoModeratorFetchRevScoreJob::run
	 * when there is a bad request response returns false
	 */
	public function testRunWithBadRequestReturnsFailure() {
		$this->overrideConfigValue( 'OresModels', [
			'revertrisklanguageagnostic' => [ 'enabled' => false, 'namespaces' => [ 0 ] ]
		] );
		$this->overrideConfigValue( 'AutoModeratorMultiLingualRevertRisk', null );
		$this->overrideConfigValue( 'AutoModeratorEnableMultilingual', false );

		[ $wikiPage, $user, $rev, $title ] = $this->createTestPage();

		$score = [];

		$this->installMockHttp( $this->makeFakeHttpRequest( json_encode( $score ), 400 ) );

		$job = new AutoModeratorFetchRevScoreJob( $title,
			[
				'wikiPageId' => $wikiPage['id'],
				'revId' => $rev->getId(),
				'originalRevId' => false,
				'userId' => $user->getId(),
				'userName' => $user->getName(),
				'tags' => [],
				'scores' => null,
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
		$this->overrideConfigValue( 'OresModels', [
			'revertrisklanguageagnostic' => [ 'enabled' => false, 'namespaces' => [ 0 ] ]
		] );
		$this->overrideConfigValue( 'AutoModeratorMultiLingualRevertRisk', null );
		$this->overrideConfigValue( 'AutoModeratorEnableMultilingual', false );

		[ $wikiPage, $user, $rev, $title ] = $this->createTestPage();

		$score = [];

		$this->installMockHttp( $this->makeFakeHttpRequest( json_encode( $score ), 500 ) );

		$job = new AutoModeratorFetchRevScoreJob( $title,
			[
				'wikiPageId' => $wikiPage['id'],
				'revId' => $rev->getId(),
				'originalRevId' => false,
				'userId' => $user->getId(),
				'userName' => $user->getName(),
				'tags' => [],
				'scores' => null,
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
		$this->overrideConfigValue( 'OresModels', [
			'revertrisklanguageagnostic' => [ 'enabled' => false, 'namespaces' => [ 0 ] ]
		] );
		$this->overrideConfigValue( 'AutoModeratorMultiLingualRevertRisk', null );
		$this->overrideConfigValue( 'AutoModeratorEnableMultilingual', false );

		[ $wikiPage, $user, $rev, $title ] = $this->createTestPage();

		$score = [];

		$this->installMockHttp( $this->makeFakeHttpRequest( json_encode( $score ), 504 ) );

		$job = new AutoModeratorFetchRevScoreJob( $title,
			[
				'wikiPageId' => $wikiPage['id'],
				'revId' => $rev->getId(),
				'originalRevId' => false,
				'userId' => $user->getId(),
				'userName' => $user->getName(),
				'tags' => [],
				'scores' => null,
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
		$this->overrideConfigValue( 'OresModels', [
			'revertrisklanguageagnostic' => [ 'enabled' => false, 'namespaces' => [ 0 ] ]
		] );
		$this->overrideConfigValue( 'AutoModeratorMultiLingualRevertRisk', null );
		$this->overrideConfigValue( 'AutoModeratorEnableMultilingual', false );

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
				'wikiPageId' => $wikiPage['id'],
				'revId' => 9999999999,
				'originalRevId' => false,
				'userId' => $user->getId(),
				'userName' => $user->getName(),
				'tags' => [],
				'scores' => null
			]
		);

		$success = $job->run();

		$this->assertFalse( $success );
	}

	/**
	 * @covers AutoModerator\Services\AutoModeratorFetchRevScoreJob::run
	 * @covers AutoModerator\Services\AutoModeratorFetchRevScoreJob::getOresRevScore
	 * Tests fetching the score from ORES extension. Will get scores from the parameters
	 */
	public function testRunWithORESExtensionWithScoresSuccess() {
		$this->markTestSkippedIfExtensionNotLoaded( 'ORES' );

		$this->overrideConfigValue( 'OresModels', [
			'revertrisklanguageagnostic' => [ 'enabled' => true, 'namespaces' => [ 0 ] ]
		] );
		$this->overrideConfigValue( 'AutoModeratorMultiLingualRevertRisk', null );
		$this->overrideConfigValue( 'AutoModeratorEnableMultilingual', false );

		[ $wikiPage, $user, $rev, $title ] = $this->createTestPage();

		$score = [];
		$score[$rev->getId()] = [
			'revertrisklanguageagnostic' => [
				'score' => [
					'prediction' => true,
					'probability' => [
						'true' => 0.9987422,
						'false' => 0.00012578,
					]
				]
			]
		];

		$job = new AutoModeratorFetchRevScoreJob( $title,
			[
				'wikiPageId' => $wikiPage['id'],
				'revId' => $rev->getId(),
				'originalRevId' => false,
				'userId' => $user->getId(),
				'userName' => $user->getName(),
				'tags' => [],
				'scores' => $score
			]
		);

		$success = $job->run();

		$this->assertTrue( $success );
	}

	/**
	 * @covers AutoModerator\Services\AutoModeratorFetchRevScoreJob::run
	 * @covers AutoModerator\Services\AutoModeratorFetchRevScoreJob::getOresRevScore
	 * @covers AutoModerator\OresScoreFetcher::getOresScore
	 * Tests fetching the score from ORES extension. Will get scores from a DB query
	 */
	public function testRunWithORESExtensionWithNoScoresSuccess() {
		$this->markTestSkippedIfExtensionNotLoaded( 'ORES' );

		$this->overrideConfigValue( 'OresModels', [
			'revertrisklanguageagnostic' => [ 'enabled' => true, 'namespaces' => [ 0 ] ]
		] );
		$this->overrideConfigValue( 'AutoModeratorMultiLingualRevertRisk', null );
		$this->overrideConfigValue( 'AutoModeratorEnableMultilingual', false );

		[ $wikiPage, $user, $rev, $title ] = $this->createTestPage();

		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'ores_classification' )
			->row( [
				'oresc_model' => $this->ensureOresModel( 'revertrisklanguageagnostic' ),
				'oresc_class' => 1,
				'oresc_probability' => 0.945,
				'oresc_is_predicted' => 1,
				'oresc_rev' => $rev->getId(),
			] )
			->caller( __METHOD__ )
			->execute();

		$count = $this->getDb()->newSelectQueryBuilder()
			->select( [ '*' ] )
			->from( 'ores_classification' )
			->join( 'ores_model', null, [ 'oresm_id = oresc_model' ] )
			->where( [ 'oresc_rev' => $rev->getId(), 'oresm_name' => 'revertrisklanguageagnostic' ] )
			->caller( __METHOD__ )
			->fetchRowCount();

		$this->assertSame( 1, $count );

		$job = new AutoModeratorFetchRevScoreJob( $title,
			[
				'wikiPageId' => $wikiPage['id'],
				'revId' => $rev->getId(),
				'originalRevId' => false,
				'userId' => $user->getId(),
				'userName' => $user->getName(),
				'tags' => [],
				'scores' => null
			]
		);

		$success = $job->run();

		$this->assertTrue( $success );
	}

	/**
	 * @covers AutoModerator\Services\AutoModeratorFetchRevScoreJob::run
	 * @covers AutoModerator\Services\AutoModeratorFetchRevScoreJob::getLiftWingRevScore
	 * @covers AutoModerator\Services\AutoModeratorFetchRevScoreJob::getOresRevScore
	 * @covers AutoModerator\OresScoreFetcher::getOresScore
	 * Tests fetching the score from ORES extension. There are no scores in the job params
	 * or in the database. Resorting to Liftwing API query
	 */
	public function testRunWithORESExtensionNoORESDataSuccess() {
		$this->markTestSkippedIfExtensionNotLoaded( 'ORES' );
		$this->overrideConfigValue( 'OresModels', [
			'revertrisklanguageagnostic' => [ 'enabled' => false, 'namespaces' => [ 0 ] ]
		] );
		$this->overrideConfigValue( 'AutoModeratorMultiLingualRevertRisk', null );
		$this->overrideConfigValue( 'AutoModeratorEnableMultilingual', false );

		[ $wikiPage, $user, $rev, $title ] = $this->createTestPage();

		// Inserting a row that does not link to the revision we just created
		// This will force the job to run the LiftWing command
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'ores_classification' )
			->row( [
				'oresc_model' => $this->ensureOresModel( 'revertrisklanguageagnostic' ),
				'oresc_class' => 1,
				'oresc_probability' => 0.998,
				'oresc_is_predicted' => 1,
				'oresc_rev' => 9012,
			] )
			->caller( __METHOD__ )
			->execute();

		$score = [
			'model_name' => 'revertrisklanguageagnostic',
			'model_version' => '3',
			'wiki_db' => 'enwiki',
			'revision_id' => 24601,
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
				'wikiPageId' => $wikiPage['id'],
				'revId' => $rev->getId(),
				'originalRevId' => false,
				'userId' => $user->getId(),
				'userName' => $user->getName(),
				'tags' => [],
				'scores' => [ $score ]
			]
		);

		$success = $job->run();

		$this->assertTrue( $success );
	}

	/**
	 * @covers AutoModerator\Services\AutoModeratorFetchRevScoreJob::run
	 */
	public function testRunSuccessManualRevertMultilingualEnabled() {
		$this->overrideConfigValue( 'OresModels', [
			'revertrisklanguageagnostic' => [ 'enabled' => false, 'namespaces' => [ 0 ] ]
		] );
		$this->overrideConfigValue( 'AutoModeratorMultiLingualRevertRisk', [ 'enwiki' ] );
		$this->overrideConfigValue( 'AutoModeratorEnableMultilingual', true );

		$wikiPage = $this->insertPage( 'TestJob', 'Test text' );
		$user = $this->getTestUser()->getUserIdentity();
		$this->editPage( $this->getExistingTestPage( $wikiPage['title'] ), 'Content' );
		$revisionStore = $this->getServiceContainer()->getRevisionStore();
		$rev = $revisionStore->getRevisionByPageId( $wikiPage['id'] );
		// Add more edits so that $rev is not the most recent revision, causing a revert conflict
		$this->editPage( $this->getExistingTestPage( $wikiPage['title'] ), 'Content33' );
		$this->editPage( $this->getExistingTestPage( $wikiPage['title'] ), 'Content44' );
		$title = $wikiPage['title'];

		$score = [
			'model_name' => 'revertrisk-multilingual',
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
				'wikiPageId' => $wikiPage['id'],
				'revId' => $rev->getId(),
				'originalRevId' => false,
				'userId' => $user->getId(),
				'userName' => $user->getName(),
				'tags' => [],
				'scores' => null,
			]
		);

		$success = $job->run();
		$this->assertTrue( $success );
	}

	private function ensureOresModel( $name ) {
		$modelInfo = [
			'oresm_name' => $name,
			'oresm_version' => '0.0.1',
			'oresm_is_current' => 1
		];
		$model = $this->getDb()->newSelectQueryBuilder()
			->select( 'oresm_id' )
			->from( 'ores_model' )
			->where( $modelInfo )
			->fetchField();
		if ( $model ) {
			return $model;
		}

		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'ores_model' )
			->row( $modelInfo )
			->caller( __METHOD__ )
			->execute();

		return $this->getDb()->insertId();
	}
}
