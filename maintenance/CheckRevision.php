<?php

namespace AutoModerator\Maintenance;

use AutoModerator\LiftWingClient;
use AutoModerator\RevisionCheck;
use AutoModerator\Util;
use Maintenance;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

/**
 * Check a revision to see if it would be reverted
 */
class CheckRevision extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'AutoModerator' );
		$this->addDescription(
			'Check a revision and report if it would be reverted based on scoring from a machine learning model.'
		);
		$this->addOption( 'revid', 'Revision ID', true, true );
		$this->addOption( 'client', 'Client for score fetching', false, true );
	}

	public function execute() {
		if ( !ctype_digit( $this->getOption( 'revid' ) ) ) {
			$this->output( "'revid' must be an integer\n" );
			return;
		}
		$revId = (int)$this->getOption( 'revid' );

		// setup dependencies that we get for free when running in a hook.
		$services = MediaWikiServices::getInstance();
		$changeTagsStore = $services->getChangeTagsStore();
		$contentHandlerFactory = $services->getContentHandlerFactory();
		$revisionLookup = $services->getRevisionLookup();
		$revisionStore = $services->getRevisionStoreFactory()->getRevisionStore();
		$userGroupManager = $services->getUserGroupManager();
		$wikiPageFactory = $services->getWikiPageFactory();
		$autoModeratorUser = Util::getAutoModeratorUser();
		$dbr = $this->getReplicaDB();
		$tags = $changeTagsStore->getTags( $dbr, null, $revId );
		$rev = $revisionLookup->getRevisionById( $revId );
		// Check if revision is not null
		if ( !$rev ) {
			return;
		}
		$wikiPage = $wikiPageFactory->newFromTitle( $rev->getPage() );

		$contentHandler = $contentHandlerFactory->getContentHandler( $rev->getSlot(
			SlotRecord::MAIN,
			RevisionRecord::RAW
		)->getModel() );
		$logger = LoggerFactory::getInstance( 'AutoModerator' );
		$revisionCheck = new RevisionCheck(
				$wikiPage,
				$rev,
				// @fixme: we should actually check for
				//  $originalRevId as defined in onRevisionFromEditComplete
				false,
				$rev->getUser(),
				$tags,
				$autoModeratorUser,
				$revisionStore,
				$changeTagsStore,
				$contentHandler,
				$logger,
				$userGroupManager
		);
		if ( !$revisionCheck->passedPreCheck ) {
			$this->output( "precheck skipped rev:\t$revId\n" );
			return;
		}

		// Get a real score or optionally set a fake score
		$score = [];
		switch ( $this->getOption( 'client', 'liftwing' ) ) {
			case 'liftwing':
				$liftWingClient = new LiftWingClient(
					'revertrisk-language-agnostic',
					'en',
					$revisionCheck->passedPreCheck
				);
				$score = $liftWingClient->get( $rev->getId() );
				break;
			case 'testfail':
				$score = [
						'model_name' => 'revertrisk-language-agnostic',
						'model_version' => '3',
						'wiki_db' => 'enwiki',
						'revision_id' => $revId,
						'output' => [
								'prediction' => true,
								'probabilities' => [
										'true' => 0.806738942861557,
										'false' => 0.193261057138443,
								],
						],
				];
				break;
			case 'testpass':
				$score = [
						'model_name' => 'revertrisk-language-agnostic',
						'model_version' => '3',
						'wiki_db' => 'enwiki',
						'revision_id' => $revId,
						'output' => [
								'prediction' => false,
								'probabilities' => [
										'true' => 0.193261057138443,
										'false' => 0.806738942861557,
								],
						],
				];
				break;
			default:
				break;
		}
		$reverted = json_encode( $revisionCheck->maybeRevert( $score ), JSON_FORCE_OBJECT, JSON_PRETTY_PRINT );
		$scoreStr = json_encode( $score, JSON_PRETTY_PRINT );
		$this->output( "Revision ID:\t$revId\nWould revert?\t$reverted\nScore:\t$scoreStr\n" );
	}
}

$maintClass = CheckRevision::class;
require_once RUN_MAINTENANCE_IF_MAIN;
