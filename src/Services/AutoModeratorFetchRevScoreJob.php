<?php
/**
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace AutoModerator\Services;

use AutoModerator\AutoModeratorRevisionStore;
use AutoModerator\AutoModeratorServices;
use AutoModerator\OresScoreFetcher;
use AutoModerator\RevisionCheck;
use AutoModerator\Util;
use MediaWiki\Config\Config;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\JobQueue\Job;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Title\Title;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Wikimedia\Rdbms\IConnectionProvider;

class AutoModeratorFetchRevScoreJob extends Job {

	/**
	 * @var int
	 */
	private $wikiPageId;

	/**
	 * @var int
	 */
	private $revId;

	/**
	 * @var bool
	 */
	private bool $isRetryable = true;

	/**
	 * @var ?array
	 */
	private $scores;

	/**
	 * @param Title $title
	 * @param array $params
	 *    - 'wikiPageId': (int)
	 *    - 'revId': (int)
	 *    - 'originalRevId': (int|false)
	 *    - 'userId': (int)
	 *    - 'userName': (string)
	 *    - 'tags': (string[])
	 *    - 'scores': (?array)
	 */
	public function __construct( Title $title, array $params ) {
		parent::__construct( 'AutoModeratorFetchRevScoreJob', $title, $params );
		$this->wikiPageId = $params[ 'wikiPageId' ];
		$this->revId = $params[ 'revId' ];
		$this->scores = $params[ 'scores' ];
	}

	public function run(): bool {
		$services = MediaWikiServices::getInstance();
		$autoModeratorServices = AutoModeratorServices::wrap( $services );
		$wikiPageFactory = $services->getWikiPageFactory();
		$revisionStore = $services->getRevisionStore();
		$userGroupManager = $services->getUserGroupManager();
		$config = $services->getMainConfig();
		$wikiConfig = $autoModeratorServices->getAutoModeratorWikiConfig();
		$connectionProvider = $services->getConnectionProvider();
		$autoModeratorUser = Util::getAutoModeratorUser( $config, $userGroupManager );
		$wikiId = Util::getWikiID( $config );
		$logger = LoggerFactory::getInstance( 'AutoModerator' );
		$userFactory = $services->getUserFactory();

		$rev = $revisionStore->getRevisionById( $this->revId );
		if ( $rev === null ) {
			$error = "rev {$this->revId} not found";
			$logger->debug( __METHOD__ . " - " . $error );
			$this->setLastError( $error );
			$this->setAllowRetries( true );
			return false;
		}
		try {
			$user = $userFactory->newFromAnyId(
				$this->params['userId'],
				$this->params['userName']
			);
			$maxReverts = $wikiConfig->get( 'AutoModeratorUserRevertsPerPage' );
			if ( $wikiConfig->get( 'AutoModeratorEnableUserRevertsPerPage' ) && $maxReverts ) {
				$autoModeratorRevisionStore = new AutoModeratorRevisionStore(
					$connectionProvider->getReplicaDatabase(),
					$user,
					$autoModeratorUser,
					$this->wikiPageId,
					$revisionStore,
					$maxReverts
				);
				if ( $autoModeratorRevisionStore->hasReachedMaxRevertsForUser() ) {
					$logger->debug( __METHOD__ . " - AutoModerator has reached the maximum reverts for this user" );
					return true;
				}
			}
			$response = false;
			// Model name defaults to language-agnostic model name
			$revertRiskModelName = Util::getRevertRiskModel( $config, $wikiConfig );
			if ( ExtensionRegistry::getInstance()->isLoaded( 'ORES' ) ) {
				$oresModels = $config->get( 'OresModels' );

				if ( array_key_exists( $revertRiskModelName, $oresModels ) &&
					$oresModels[ $revertRiskModelName ][ 'enabled' ] ) {
					// ORES is loaded and the model is enabled, fetching the score from there
					$response = $this->getOresRevScore( $connectionProvider, $revertRiskModelName, $wikiId, $logger );
				}
			}

			if ( !$response ) {
				// ORES is not loaded, or a score couldn't be retrieved from the extension
				$response = $this->getLiftWingRevScore( $config, $wikiConfig );
			}
			if ( !$response ) {
				$error = "score could not be retrieved for {$this->revId}";
				$logger->debug( __METHOD__ . " - " . $error );
				$this->setLastError( $error );
				$this->setAllowRetries( true );
				return false;
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
					$wikiPageFactory->newFromID( $this->wikiPageId ),
					$autoModeratorUser->getUser(),
					$user,
					$config,
					$wikiConfig
				),
				true
			);
			$reverted = $revisionCheck->maybeRollback( $response, $revertRiskModelName );

		} catch ( RuntimeException $exception ) {
			$logger->debug( __METHOD__ . " - " . $exception->getMessage() );
			$this->setLastError( $exception->getMessage() );
			return false;
		}
		// Revision reverted
		if ( array_key_exists( '1', $reverted ) && $reverted['1'] === 'success' ) {
			return true;
		}
		// Revert attempted but failed
		if ( array_key_exists( '0', $reverted ) && $reverted['0'] === 'failure' ) {
			$this->setLastError( 'Revision ' . $this->revId . ' requires a manual revert.' );
			$this->setAllowRetries( false );
			return false;
		}
		// Revision passed check; noop.
		if ( array_key_exists( '0', $reverted ) && $reverted['0'] === 'Not reverted' ) {
			return true;
		}
		// Revision unable to be reverted due to an edit conflict or race condition in the job queue
		if ( array_key_exists( '0', $reverted ) && $reverted['0'] === 'success' ) {
			$logger->debug( __METHOD__ . " - " . $reverted['0'] );
			$this->setAllowRetries( false );
			return true;
		}
		// Revert attempted but failed to save revision record due to unknown reason
		if ( array_key_exists( '0', $reverted ) ) {
			$logger->debug( __METHOD__ . " - " . $reverted['0'] );
			$this->setLastError( $reverted['0'] );
			$this->setAllowRetries( true );
			return false;
		}

		$logger->debug( __METHOD__ . " - Default false" );
		return false;
	}

	/**
	 * Obtains a score from LiftWing API
	 * @param Config $config
	 * @param Config $wikiConfig
	 * @return array|false
	 */
	private function getLiftWingRevScore( Config $config, Config $wikiConfig ) {
		$liftWingClient = Util::initializeLiftWingClient( $config, $wikiConfig );
		$response = $liftWingClient->get( $this->revId );
		$this->setAllowRetries( $response[ 'allowRetries' ] ?? true );
		if ( isset( $response['errorMessage'] ) ) {
			$this->setLastError( $response['errorMessage'] );
			return false;
		}
		return $response;
	}

	/**
	 * Obtains a score from ORES classification table
	 * @param IConnectionProvider $connectionProvider
	 * @param string $revertRiskModelName
	 * @param string $wikiId
	 * @param LoggerInterface $logger
	 * @return array|false
	 */
	private function getOresRevScore( IConnectionProvider $connectionProvider, string $revertRiskModelName,
		string $wikiId, LoggerInterface $logger ) {
		if ( $this->scores ) {
			foreach ( $this->scores as $rev_id => $score ) {
				if ( $rev_id === $this->revId && array_key_exists( $revertRiskModelName, $score ) ) {
					return [
						'output' => [
							'probabilities' => [
								'true' => $score[ $revertRiskModelName ][ 'score' ][ 'probability' ][ 'true' ]
							]
						]
					];
				}
			}
		}
		// If there where no score returns, we should try to fetch the score from the database
		$oresScoreFetcher = new OresScoreFetcher( $connectionProvider );
		$logger->debug( 'Score was not found in scores hook array; getting it from ORES DB' );
		$oresDbRow = $oresScoreFetcher->getOresScore( $this->revId );
		if ( !$oresDbRow ) {
			// Database query did not find revision score, returning false
			return false;
		}
		// Creating a response that is similar to the one LiftWing API returns
		// Omitting some unused information
		return [
			'model_name' => $oresDbRow->oresm_name,
			'model_version' => $oresDbRow->oresm_version,
			'wiki_db' => $wikiId,
			'revision_id' => $this->revId,
			'output' => [
				'probabilities' => [
					'true' => $oresDbRow->oresc_probability
				],
			],
		];
	}

	private function setAllowRetries( bool $isRetryable ) {
		$this->isRetryable = $isRetryable;
	}

	/**
	 * @inheritDoc
	 * @codeCoverageIgnore
	 */
	public function allowRetries(): bool {
		return $this->isRetryable;
	}

	/**
	 * @inheritDoc
	 * @codeCoverageIgnore
	 */
	public function ignoreDuplicates(): bool {
		return true;
	}
}
