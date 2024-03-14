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

namespace AutoModerator\Tests\Unit\MediaWiki\Hooks;

use AutoModerator\Hooks\LoadExtensionSchemaUpdates;
use DatabaseUpdater;
use MediaWikiUnitTestCase;
use WikiMedia\Rdbms\IDatabase;

/**
 *
 * @covers AutoModerator\Hooks\LoadExtensionSchemaUpdates
 * @group AutoModerator
 * @group extensions
 * @group Database
 */
class LoadExtensionSchemaUpdatesHookTest extends MediaWikiUnitTestCase {

	public function testOnLoadExtensionSchemaUpdates() {
		$db = $this->createMock( IDatabase::class );
		$hookHandler = new LoadExtensionSchemaUpdates();
		$updater = $this->createMock( DatabaseUpdater::class );
		$updater->expects( $this->any() )->method( 'getDB' )->willReturn( $db );
		$tableCount = 2;
		$updater->expects( $this->exactly( $tableCount ) )->method( 'addExtensionTable' );
		$hookHandler->onLoadExtensionSchemaUpdates( $updater );
	}

}
