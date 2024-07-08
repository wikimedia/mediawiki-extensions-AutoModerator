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
use AutoModerator\RevisionCheck;
use AutoModerator\Util;
use Job;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use RuntimeException;

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
	 * @param Title $title
	 * @param array $params
	 *    - 'wikiPageId': (int)
	 *    - 'revId': (int)
	 *    - 'originalRevId': (int|false)
	 *    - 'userId': (int)
	 *    - 'userName': (string)
	 *    - 'tags': (string[])
	 *    - 'undoSummary': (string)
	 */
	public function __construct( Title $title, array $params ) {
		parent::__construct( 'AutoModeratorFetchRevScoreJob', $title, $params );
		$this->wikiPageId = $params[ 'wikiPageId' ];
		$this->revId = $params[ 'revId' ];
		$this->originalRevId = $params[ 'originalRevId' ];
		$this->tags = $params[ 'tags' ];
		$this->undoSummary = $params[ 'undoSummary' ];
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
		$userFactory = $services->getUserFactory();
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

		$liftWingClient = Util::initializeLiftWingClient( $config );
		$reverted = [];
		try {
			$response = $liftWingClient->get( $this->revId );
			$this->setAllowRetries( $response[ 'allowRetries' ] ?? true );
			if ( isset( $response['errorMessage'] ) ) {
				$this->setLastError( $response['errorMessage'] );
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
				$userGroupManager,
				$restrictionStore,
				$wikiId,
				$this->undoSummary,
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

	private function setAllowRetries( bool $isRetryable ) {
		$this->isRetryable = $isRetryable;
	}

	/** @inheritDoc */
	public function allowRetries(): bool {
		return $this->isRetryable;
	}

	/** @inheritDoc */
	public function ignoreDuplicates(): bool {
		return true;
	}
}
