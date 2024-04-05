<?php

namespace AutoModerator\Tests;

use AutoModerator\Maintenance\CheckRevision;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;

/**
 * @group AutoModerator
 * @group extensions
 * @group Database
 * @covers \AutoModerator\Maintenance\CheckRevision
 */
class CheckRevisionTest extends MaintenanceBaseTestCase {
	public function getMaintenanceClass() {
		return CheckRevision::class;
	}

	public function testNotARevision() {
		$this->maintenance->loadWithArgv( [ '--revid', 'not_a_rev_id' ] );
		$this->maintenance->execute();

		$this->expectOutputRegex( '/\'revid\' must be an integer/' );
	}

	public function testZeroRevision() {
		$this->maintenance->loadWithArgv( [ '--revid', '0' ] );
		$this->maintenance->execute();

		$this->expectOutputRegex( '/\'revid\' must be greater than zero/' );
	}
}
