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

namespace AutoModerator\Hooks;

use DatabaseUpdater;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class LoadExtensionSchemaUpdates implements LoadExtensionSchemaUpdatesHook {

	/**
	 * @param DatabaseUpdater $updater
	 * @return void
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$type = $updater->getDB()->getType();
		$extPath = dirname( __DIR__, 2 );
		$typePath = $type === 'mysql' ? '' : $type . '/';
		$sqlPath = $extPath . '/sql/' . $typePath;
		$updater->addExtensionTable(
			'automoderator_rev_score',
			$sqlPath . 'tables-generated.sql'
		);
		$updater->addExtensionTable(
			'automoderator_model',
			$sqlPath . 'tables-generated.sql'
		);
	}

}
