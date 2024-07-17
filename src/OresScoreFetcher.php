<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 *
 * @file
 */

namespace AutoModerator;

use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IReadableDatabase;

class OresScoreFetcher {

	private IReadableDatabase $dbr;

	/**
	 * @param IConnectionProvider $dbProvider
	 */
	public function __construct( IConnectionProvider $dbProvider ) {
		$this->dbr = $dbProvider->getReplicaDatabase();
	}

	/**
	 * @param int $revId
	 * @return mixed|false
	 */
	public function getOresScore( int $revId ) {
		return $this->dbr->newSelectQueryBuilder()
			->select( [ 'oresc_probability', 'oresm_name', 'oresm_version' ] )
			->from( 'ores_classification' )
			->join( 'ores_model', null, [ 'oresm_id = oresc_model' ] )
			->where( [ 'oresc_rev' => $revId, 'oresm_name' => 'revertrisklanguageagnostic' ] )
			->caller( __METHOD__ )
			->fetchRow();
	}
}
