<?php

namespace AutoModerator\Maintenance;

use AutoModerator\AutoModeratorServices;
use AutoModerator\RevisionCheck;
use AutoModerator\Services\AutoModeratorRollback;
use AutoModerator\Util;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Maintenance\Maintenance;
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
		if ( $revId === 0 ) {
			$this->output( "'revid' must be greater than zero\n" );
			return;
		}

		// setup dependencies that we get for free when running in a hook.
		$services = MediaWikiServices::getInstance();
		$autoModeratorServices = AutoModeratorServices::wrap( $services );

		$changeTagsStore = $services->getChangeTagsStore();
		$config = $services->getMainConfig();
		$wikiConfig = $autoModeratorServices->getAutoModeratorWikiConfig();
		$contentHandlerFactory = $services->getContentHandlerFactory();
		$revisionLookup = $services->getRevisionLookup();
		$revisionStore = $services->getRevisionStoreFactory()->getRevisionStore();
		$userGroupManager = $services->getUserGroupManager();
		$wikiPageFactory = $services->getWikiPageFactory();
		$restrictionStore = $services->getRestrictionStore();
		$permissionManager = $services->getPermissionManager();
		$autoModeratorUser = Util::getAutoModeratorUser( $config, $userGroupManager );
		$dbr = $this->getReplicaDB();
		$tags = $changeTagsStore->getTags( $dbr, null, $revId );
		$rev = $revisionLookup->getRevisionById( $revId );
		// Check if revision or the revision user is not null
		$userIdentity = $rev->getUser();
		if ( !$rev || !$userIdentity ) {
			return;
		}
		$wikiPageId = $rev->getPageId();
		$contentHandler = $contentHandlerFactory->getContentHandler( $rev->getSlot(
			SlotRecord::MAIN,
			RevisionRecord::RAW
		)->getModel() );
		$logger = LoggerFactory::getInstance( 'AutoModerator' );
		if ( !RevisionCheck::revertPreCheck(
			$userIdentity,
			$autoModeratorUser,
			$logger,
			$revisionStore,
			$tags,
			$restrictionStore,
			$wikiPageFactory,
			$wikiConfig,
			$rev,
			$permissionManager
		) ) {
			$this->output( "precheck skipped rev:\t$revId\n" );
			return;
		}

		$revisionCheck = new RevisionCheck(
			$wikiConfig,
			$config,
			new AutoModeratorRollback(
				new ServiceOptions( AutoModeratorRollback::CONSTRUCTOR_OPTIONS, $config ),
				$services->getDBLoadBalancerFactory(),
				$revisionStore,
				$services->getTitleFormatter(),
				$services->getHookContainer(),
				$wikiPageFactory,
				$services->getActorMigration(),
				$services->getActorNormalization(),
				$wikiPageFactory->newFromID( $wikiPageId ),
				$autoModeratorUser->getUser(),
				$rev->getUser(),
				$config,
				$wikiConfig
			)
		);

		// Get a real score or optionally set a fake score
		$score = [];
		switch ( $this->getOption( 'client', 'liftwing' ) ) {
			case 'liftwing':
				$liftWingClient = Util::initializeLiftWingClient( $config, $wikiConfig );
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
		$revertRiskModelName = Util::getRevertRiskModel( $config, $wikiConfig );
		$reverted = json_encode( $revisionCheck->maybeRollback( $score, $revertRiskModelName ),
			JSON_FORCE_OBJECT,
			JSON_PRETTY_PRINT );
		$scoreStr = json_encode( $score, JSON_PRETTY_PRINT );
		$this->output( "Revision ID:\t$revId\nWould revert?\t$reverted\nScore:\t$scoreStr\n" );
	}
}

$maintClass = CheckRevision::class;
require_once RUN_MAINTENANCE_IF_MAIN;
