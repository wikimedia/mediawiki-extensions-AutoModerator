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

use AutoModerator\Config\AutoModeratorConfigLoaderStaticTrait;
use AutoModerator\OresScoreFetcher;
use AutoModerator\RevisionCheck;
use AutoModerator\Util;
use Job;
use MediaWiki\Config\Config;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Wikimedia\Rdbms\IConnectionProvider;

class AutoModeratorFetchRevScoreJob extends Job {

	use AutoModeratorConfigLoaderStaticTrait;

	/**
	 * @var int
	 */
	private $wikiPageId;

	/**
	 * @var int
	 */
	private $revId;

	/**
	 * @var int|false
	 */
	private $originalRevId;

	/**
	 * @var string[]
	 */
	private $tags;

	/**
	 * @var bool
	 */
	private bool $isRetryable = true;

	/**
	 * @var string
	 */
	private string $undoSummary;

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
	 *    - 'undoSummary': (string)
	 *    - 'scores': (?array)
	 */
	public function __construct( Title $title, array $params ) {
		parent::__construct( 'AutoModeratorFetchRevScoreJob', $title, $params );
		$this->wikiPageId = $params[ 'wikiPageId' ];
		$this->revId = $params[ 'revId' ];
		$this->originalRevId = $params[ 'originalRevId' ];
		$this->tags = $params[ 'tags' ];
		$this->undoSummary = $params[ 'undoSummary' ];
		$this->scores = $params[ 'scores' ];
	}

	public function run(): bool {
		$services = MediaWikiServices::getInstance();
		$wikiPageFactory = $services->getWikiPageFactory();
		$revisionStore = $services->getRevisionStore();
		$contentHandlerFactory = $services->getContentHandlerFactory();
		$userGroupManager = $services->getUserGroupManager();
		$restrictionStore = $services->getRestrictionStore();
		$config = $services->getMainConfig();
		$wikiConfig = $this->getAutoModeratorWikiConfig();
		$connectionProvider = $services->getConnectionProvider();

		$userFactory = $services->getUserFactory();
		$permissionManager = $services->getPermissionManager();
		$user = $userFactory->newFromAnyId(
			$this->params['userId'],
			$this->params['userName']
		);
		$autoModeratorUser = Util::getAutoModeratorUser( $config, $userGroupManager );
		$wikiId = Util::getWikiID( $config );
		$logger = LoggerFactory::getInstance( 'AutoModerator' );

		$rev = $revisionStore->getRevisionById( $this->revId );
		if ( $rev === null ) {
			$message = 'rev rev_id not found';
			$error = strtr( $message, [
				'rev_id' => (string)$this->revId
			] );
			$this->setLastError( $error );
			$this->setAllowRetries( true );
			return false;
		}
		$contentHandler = $contentHandlerFactory->getContentHandler( $rev->getSlot(
			SlotRecord::MAIN,
			RevisionRecord::RAW
		)->getModel() );

		try {
			$oresModels = $config->get( 'OresModels' );
			$response = false;
			if ( ExtensionRegistry::getInstance()->isLoaded( 'ORES' ) &&
				array_key_exists( 'revertrisklanguageagnostic', $oresModels ) &&
				$oresModels[ 'revertrisklanguageagnostic' ][ 'enabled' ] ) {
				// ORES is loaded and the model is enabled, fetching the score from there
				$response = $this->getOresRevScore( $connectionProvider, $config, $wikiId, $logger );
			}
			if ( !$response ) {
				// ORES is not loaded, or a score couldn't be retrieved from the extension
				$response = $this->getLiftWingRevScore( $config );
			}
			if ( !$response ) {
				$message = 'score could not be retrieved for rev_id';
				$error = strtr( $message, [
					'rev_id' => (string)$this->revId
				] );
				$this->setLastError( $error );
				$this->setAllowRetries( true );
				return false;
			}
			$revisionCheck = new RevisionCheck(
				$this->wikiPageId,
				$wikiPageFactory,
				$this->revId,
				$this->originalRevId,
				$user,
				$this->tags,
				$autoModeratorUser,
				$revisionStore,
				$config,
				$wikiConfig,
				$contentHandler,
				$logger,
				$restrictionStore,
				$wikiId,
				$this->undoSummary,
				$permissionManager,
				true
			);
			$reverted = $revisionCheck->maybeRevert( $response );

		} catch ( RuntimeException $exception ) {
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

		return false;
	}

	/**
	 * Obtains a score from LiftWing API
	 * @param Config $config
	 * @return array|false
	 */
	private function getLiftWingRevScore( Config $config ) {
		$liftWingClient = Util::initializeLiftWingClient( $config );
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
	 * @param Config $config
	 * @param string $wikiId
	 * @param LoggerInterface $logger
	 * @return array|false
	 */
	private function getOresRevScore( IConnectionProvider $connectionProvider, Config $config, string $wikiId,
		LoggerInterface $logger ) {
		if ( $this->scores ) {
			foreach ( $this->scores as $rev_id => $score ) {
				if ( $rev_id === $this->revId && array_key_exists( 'revertrisklanguageagnostic', $score ) ) {
					return [
						'output' => [
							'probabilities' => [
								'true' => $score[ 'revertrisklanguageagnostic' ][ 'score' ][ 'probability' ][ 'true' ]
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
