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
use AutoModerator\LiftWingClient;
use AutoModerator\RevisionCheck;
use AutoModerator\Util;
use Job;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;
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
	 * @var UserIdentity
	 */
	private $user;

	/**
	 * @var string[]
	 */
	private $tags;

	/**
	 * @param Title $title
	 * @param array $params
	 *    - 'wikiPageId': (int)
	 *    - 'revId': (int)
	 *    - 'originalRevId': (int|false)
	 *    - 'user': (UserIdentity)
	 *    - 'tags': (string[])
	 */
	public function __construct( Title $title, array $params ) {
		parent::__construct( 'AutoModeratorFetchRevScoreJob', $title, $params );
		$this->wikiPageId = $params[ 'wikiPageId' ];
		$this->revId = $params[ 'revId' ];
		$this->originalRevId = $params[ 'originalRevId' ];
		$this->user = $params[ 'user' ];
		$this->tags = $params[ 'tags' ];
	}

	public function run() {
		$services = MediaWikiServices::getInstance();
		$wikiPageFactory = $services->getWikiPageFactory();
		$revisionStore = $services->getRevisionStore();
		$contentHandlerFactory = $services->getContentHandlerFactory();
		$changeTagsStore = $services->getChangeTagsStore();
		$userGroupManager = $services->getUserGroupManager();
		$restrictionStore = $services->getRestrictionStore();
		$config = $services->getMainConfig();
		$wikiConfig = $this->getAutoModeratorWikiConfig();

		$autoModeratorUser = Util::getAutoModeratorUser( $config, $userGroupManager );
		$wikiId = Util::getWikiID( $config );
		$logger = LoggerFactory::getInstance( 'AutoModerator' );
		$rev = $revisionStore->getRevisionById( $this->revId );
		$contentHandler = $contentHandlerFactory->getContentHandler( $rev->getSlot(
			SlotRecord::MAIN,
			RevisionRecord::RAW
		)->getModel() );

		$revisionCheck = new RevisionCheck(
			$this->wikiPageId,
			$wikiPageFactory,
			$this->revId,
			$this->originalRevId,
			$this->user,
			$this->tags,
			$autoModeratorUser,
			$revisionStore,
			$changeTagsStore,
			$config,
			$wikiConfig,
			$contentHandler,
			$logger,
			$userGroupManager,
			$restrictionStore,
			$wikiId,
			true
		);
		if ( !$revisionCheck->passedPreCheck ) {
			$logger->info( 'Revision ' . $this->revId . ' did not pass the pre-check.' );
			return true;
		}
		// @todo replace 'en' with getWikiID()
		$liftWingClient = new LiftWingClient( 'revertrisk-language-agnostic', 'en', $revisionCheck->passedPreCheck );

		$logger->info( 'Fetching scores for revisions checks.' );
		try {
			$score = $liftWingClient->get( $this->revId );
			$reverted = $revisionCheck->maybeRevert( $score );
		} catch ( RuntimeException $exception ) {
			$msg = $exception->getMessage();
			$logger->error( 'There was an error trying to obtain the score: ' . $msg );
			return false;
		}

		if ( array_key_exists( '0', $reverted ) ) {
			if ( $reverted[ '0' ] === 'failure' ) {
				throw new RuntimeException( 'Revision ' . $this->revId . ' requires a manual revert.' );
			}
		}
		return true;
	}

	/** @inheritDoc */
	public function allowRetries() {
		// This is the default, but added for explicitness and clarity
		return true;
	}

	/** @inheritDoc */
	public function ignoreDuplicates() {
		return true;
	}
}
