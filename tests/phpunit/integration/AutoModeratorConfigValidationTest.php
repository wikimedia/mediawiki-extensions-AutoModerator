<?php

namespace AutoModerator\Tests;

use AutoModerator\Config\Validation\AutoModeratorConfigValidation;
use StatusValue;

/**
 * @coversDefaultClass \AutoModerator\Config\Validation\AutoModeratorConfigValidation
 */
class AutoModeratorConfigValidationTest extends \MediaWikiIntegrationTestCase {

	/**
	 * @covers ::validate when user right valid
	 */
	public function testValidateWhenUserRightValid() {
		$validator = new AutoModeratorConfigValidation();

		$result = $validator->validate( [ 'AutoModeratorSkipUserRights' => [ 'bot' ] ] );
		$this->assertEquals( StatusValue::newGood(), $result );
	}

	/**
	 * @covers ::validate when user right invalid
	 */
	public function testValidateWhenUserRightInvalid() {
		$validator = new AutoModeratorConfigValidation();

		$result = $validator->validate( [ 'AutoModeratorSkipUserRights' => [ 'bot-2' ] ] );
		$this->assertEquals( StatusValue::newFatal(
			'automoderator-config-validator-userrights-not-allowed',
			'bot-2'
		), $result );
	}
}
