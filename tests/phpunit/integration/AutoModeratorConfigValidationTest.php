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

	/**
	 * @covers ::validate when user reverts per page not a number
	 */
	public function testValidateWhenUserRevertPerPageNotANumber() {
		$validator = new AutoModeratorConfigValidation();

		$result = $validator->validate( [ 'AutoModeratorUserRevertsPerPage' => 'not a number' ] );
		$this->assertEquals( StatusValue::newFatal(
			'automoderator-config-validator-user-reverts-per-page-not-number',
			'not a number'
		), $result );
	}

	/**
	 * @covers ::validate when user reverts per page not set
	 */
	public function testValidateWhenUserRevertPerPageNotSet() {
		$validator = new AutoModeratorConfigValidation();

		$result = $validator->validate( [ 'AutoModeratorUserRevertsPerPage' => null ] );
		$this->assertEquals( StatusValue::newGood(), $result );
	}

	/**
	 * @covers ::validate when user reverts per page not set
	 */
	public function testValidateWhenUserRevertPerPageNotSetEmptyString() {
		$validator = new AutoModeratorConfigValidation();

		$result = $validator->validate( [ 'AutoModeratorUserRevertsPerPage' => '' ] );
		$this->assertEquals( StatusValue::newGood(), $result );
	}

	/**
	 * @covers ::validate when user reverts per page is a number
	 */
	public function testValidateWhenUserRevertPerPageIsANumber() {
		$validator = new AutoModeratorConfigValidation();

		$result = $validator->validate( [ 'AutoModeratorUserRevertsPerPage' => '25' ] );
		$this->assertEquals( StatusValue::newGood(), $result );
	}
}
