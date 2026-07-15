<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\AutoModerator;

use MediaWiki\Revision\RevisionStore;
use MediaWiki\User\UserIdentity;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\IResultWrapper;

readonly class AutoModeratorRevisionStore {

	public function __construct(
		private IReadableDatabase $dbr,
		private UserIdentity $user,
		private UserIdentity $autoModeratorUser,
		private int $wikiPageId,
		private RevisionStore $revisionStore,
		private int $maxReverts,
	) {
	}

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
