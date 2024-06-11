<?php

namespace AutoModerator\Maintenance;

use AutoModerator\Config\AutoModeratorConfigLoaderStaticTrait;
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

	use AutoModeratorConfigLoaderStaticTrait;

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
		if ( !ctype_digit( $this->getoption( 'revid' ) ) ) {
			$this->output( "'revid' must be an integer\n" );
			return;
		}
		$revId = (int)$this->getOption( 'revid' );
		if ( $revId === 0 ) {
			$this->output( "'revid' must be greater than zero\n" );
			return;
		}

		// setup dependencies that we get for free when running in a hook.
		$services = MediaWikiServices::getInstance();
		$changeTagsStore = $services->getChangeTagsStore();
		$config = $services->getMainConfig();
		$wikiConfig = $this->getAutoModeratorWikiConfig();
		$contentHandlerFactory = $services->getContentHandlerFactory();
		$revisionLookup = $services->getRevisionLookup();
		$revisionStore = $services->getRevisionStoreFactory()->getRevisionStore();
		$userGroupManager = $services->getUserGroupManager();
		$wikiPageFactory = $services->getWikiPageFactory();
		$restrictionStore = $services->getRestrictionStore();
		$autoModeratorUser = Util::getAutoModeratorUser( $config, $userGroupManager );
		$wikiId = Util::getWikiID( $config );
		$dbr = $this->getReplicaDB();
		$tags = $changeTagsStore->getTags( $dbr, null, $revId );
		$rev = $revisionLookup->getRevisionById( $revId );
		// Check if revision or the revision user is not null
		if ( !$rev || !$rev->getUser() ) {
			return;
		}
		$wikiPageId = $rev->getPageId();

		$contentHandler = $contentHandlerFactory->getContentHandler( $rev->getSlot(
			SlotRecord::MAIN,
			RevisionRecord::RAW
		)->getModel() );
		$logger = LoggerFactory::getInstance( 'AutoModerator' );
		$revisionCheck = new RevisionCheck(
			$wikiPageId,
			$wikiPageFactory,
			$rev->getId(),
			// @fixme: we should actually check for
			//  $originalRevId as defined in onRevisionFromEditComplete
			false,
			$rev->getUser(),
			$tags,
			$autoModeratorUser,
			$revisionStore,
			$config,
			$wikiConfig,
			$contentHandler,
			$logger,
			$userGroupManager,
			$restrictionStore,
			$wikiId,
		);
		if ( !$revisionCheck->passedPreCheck ) {
			$this->output( "precheck skipped rev:\t$revId\n" );
			return;
		}

		// Get a real score or optionally set a fake score
		$score = [];
		switch ( $this->getOption( 'client', 'liftwing' ) ) {
			case 'liftwing':
				$liftWingClient = Util::initializeLiftWingClient( $config );
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
							'true' => 1.000000000000000,
							'false' => 0.000000000000000,
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
							'true' => 0.000000000000000,
							'false' => 1.000000000000000,
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
