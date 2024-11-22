<?php

namespace AutoModerator;

use MediaWiki\Revision\RevisionStore;
use MediaWiki\User\UserIdentity;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\IResultWrapper;

class AutoModeratorRevisionStore {

	/** @var IReadableDatabase */
	private IReadableDatabase $dbr;

	/** @var UserIdentity */
	private UserIdentity $user;

	/** @var UserIdentity */
	private UserIdentity $autoModeratorUser;

	/** @var int */
	private int $wikiPageId;

	/** @var RevisionStore */
	private RevisionStore $revisionStore;

	/** @var int */
	private int $maxReverts;

	/**
	 * @param IReadableDatabase $dbr
	 * @param UserIdentity $user
	 * @param UserIdentity $autoModeratorUser
	 * @param int $wikiPageId
	 * @param RevisionStore $revisionStore
	 * @param int $maxReverts
	 */
	public function __construct( IReadableDatabase $dbr, UserIdentity $user,
		UserIdentity $autoModeratorUser, int $wikiPageId, RevisionStore $revisionStore, int $maxReverts ) {
		$this->dbr = $dbr;
		$this->user = $user;
		$this->autoModeratorUser = $autoModeratorUser;
		$this->wikiPageId = $wikiPageId;
		$this->revisionStore = $revisionStore;
		$this->maxReverts = $maxReverts;
	}

	/**
	 * @return IResultWrapper
	 */
	public function getAutoModeratorReverts(): IResultWrapper {
		return $this->dbr->newSelectQueryBuilder()
			->select( [ 'rc_this_oldid', 'rc_last_oldid' ] )
			->from( 'recentchanges' )
			->join( "actor", null, [ 'rc_actor=actor_id' ] )
			->where( [ 'actor_user' => $this->autoModeratorUser->getId(),
				'rc_cur_id' => $this->wikiPageId,
				$this->dbr->expr( 'rc_timestamp', '>', $this->dbr->timestamp( strtotime( "-1 day" ) ) )
			] )->distinct()->caller( __METHOD__ )->fetchResultSet();
	}

	/**
	 * @return bool
	 */
	public function hasReachedMaxRevertsForUser(): bool {
		$autoModeratorReverts = $this->getAutoModeratorReverts();
		if ( $autoModeratorReverts->count() < $this->maxReverts ) {
			return false;
		}
		$numberOfReverts = 0;
		foreach ( $autoModeratorReverts as $revision ) {
			$parentRevision = $this->revisionStore->getRevisionById( $revision->rc_last_oldid );
			if ( $parentRevision && $parentRevision->getUser()->getId() == $this->user->getId() ) {
				$numberOfReverts += 1;
			}
		}
		return $numberOfReverts >= $this->maxReverts;
	}
}
