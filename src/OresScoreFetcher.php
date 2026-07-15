<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\AutoModerator;

use stdClass;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IReadableDatabase;

readonly class OresScoreFetcher {

	private IReadableDatabase $dbr;

	public function __construct( IConnectionProvider $dbProvider ) {
		$this->dbr = $dbProvider->getReplicaDatabase();
	}

	public function getOresScore( int $revId ): false|stdClass {
		return $this->dbr->newSelectQueryBuilder()
			->select( [ 'oresc_probability', 'oresm_name', 'oresm_version' ] )
			->from( 'ores_classification' )
			->join( 'ores_model', null, [ 'oresm_id = oresc_model' ] )
			->where( [ 'oresc_rev' => $revId, 'oresm_name' => 'revertrisklanguageagnostic' ] )
			->caller( __METHOD__ )
			->fetchRow();
	}
}
