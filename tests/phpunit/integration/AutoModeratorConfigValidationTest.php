<?php

namespace AutoModerator\Tests;

use AutoModerator\Config\Validation\AutoModeratorConfigValidation;
use MediaWiki\Title\Title;
use StatusValue;

/**
 * @coversDefaultClass \AutoModerator\Config\Validation\AutoModeratorConfigValidation
 * @group Database
 */
class AutoModeratorConfigValidationTest extends \MediaWikiIntegrationTestCase {

	/**
	 * @covers ::validate when user right valid
	 * @covers ::validateField
	 */
	public function testValidateWhenUserRightValid() {
		$validator = new AutoModeratorConfigValidation();

		$result = $validator->validate( [ 'AutoModeratorSkipUserRights' => [ 'bot' ] ] );
		$this->assertEquals( StatusValue::newGood(), $result );
	}

	/**
	 * @covers ::validate when user right invalid
	 * @covers ::validateField
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
	 * @covers ::validateField
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
	 * @covers ::validateField
	 */
	public function testValidateWhenUserRevertPerPageNotSet() {
		$validator = new AutoModeratorConfigValidation();

		$result = $validator->validate( [ 'AutoModeratorUserRevertsPerPage' => null ] );
		$this->assertEquals( StatusValue::newGood(), $result );
	}

	/**
	 * @covers ::validate when user reverts per page not set
	 * @covers ::validateField
	 */
	public function testValidateWhenUserRevertPerPageNotSetEmptyString() {
		$validator = new AutoModeratorConfigValidation();

		$result = $validator->validate( [ 'AutoModeratorUserRevertsPerPage' => '' ] );
		$this->assertEquals( StatusValue::newGood(), $result );
	}

	/**
	 * @covers ::validate when user reverts per page is a number
	 * @covers ::validateField
	 */
	public function testValidateWhenUserRevertPerPageIsANumber() {
		$validator = new AutoModeratorConfigValidation();

		$result = $validator->validate( [ 'AutoModeratorUserRevertsPerPage' => '25' ] );
		$this->assertEquals( StatusValue::newGood(), $result );
	}

	/**
	 * @covers ::validate when multilingual threshold is a number
	 * @covers ::validateField
	 */
	public function testValidateWhenMultilingualThresholdIsANumber() {
		$validator = new AutoModeratorConfigValidation();

		$result = $validator->validate( [
			'AutoModeratorMultiLingualConfigMultilingualThreshold' => '0.992',
			'AutoModeratorMultilingualConfigEnableMultilingual' => true
		] );
		$this->assertEquals( StatusValue::newGood(), $result );
	}

	/**
	 * @covers ::validate when multilingual threshold is not a number
	 * @covers ::validateField
	 */
	public function testValidateWhenMultilingualThresholdIsNotANumber() {
		$validator = new AutoModeratorConfigValidation();
		$this->overrideConfigValue( 'AutoModeratorMultiLingualRevertRisk', [ 'enwiki' ] );

		$result = $validator->validate( [ 'AutoModeratorMultilingualConfigMultilingualThreshold' => 'oopsie' ] );
		$this->assertEquals( StatusValue::newFatal(
			'automoderator-config-validator-multilingual-threshold-not-number',
			'oopsie'
		), $result );
	}

	/**
	 * @covers ::validate when multilingual threshold is within range
	 * @covers ::validateField
	 */
	public function testValidateWhenMultilingualThresholdIsWithinRange() {
		$validator = new AutoModeratorConfigValidation();

		$result = $validator->validate( [
			'AutoModeratorMultiLingualConfigMultilingualThreshold' => '0.992',
			'AutoModeratorMultilingualConfigEnableMultilingual' => true
		] );
		$this->assertEquals( StatusValue::newGood(), $result );
	}

	/**
	 * @covers ::validate when multilingual threshold is out of range
	 */
	public function testValidateWhenMultilingualThresholdIsNotWithinRange() {
		$validator = new AutoModeratorConfigValidation();

		$result = $validator->validate( [ 'AutoModeratorMultilingualConfigMultilingualThreshold' => '0.500' ] );
		$this->assertEquals( StatusValue::newFatal(
			'automoderator-config-validator-multilingual-threshold-value-outside-range',
			'0.500'
		), $result );
	}

	/**
	 * @covers ::validate when multilingual threshold is added but the model is not enabled
	 * @covers ::validateField
	 */
	public function testValidateWhenMultilingualThresholdAddedModelNotEnabled() {
		$validator = new AutoModeratorConfigValidation();

		$result = $validator->validate( [
			'AutoModeratorMultilingualConfigMultilingualThreshold' => '0.967',
			'AutoModeratorMultilingualConfigEnableMultilingual' => false
		] );
		$this->assertEquals( StatusValue::newFatal(
			'automoderator-config-validator-multilingual-threshold-multilingual-not-enabled',
			'0.967'
		), $result );
	}

	/**
	 * @covers ::validate when the multilingual and the language-agnostic models are both enabled
	 */
	public function testValidateWhenMultilingualModelAndLanguageAgnosticEnabled() {
		$validator = new AutoModeratorConfigValidation();

		$result = $validator->validate( [
			'AutoModeratorMultilingualConfigEnableLanguageAgnostic' => true,
			'AutoModeratorMultilingualConfigEnableMultilingual' => true
		] );
		$this->assertEquals( StatusValue::newFatal(
			'automoderator-config-validator-multilingual-select-only-one-model',
			true
		), $result );
	}

	/**
	 * @covers ::validate when the talk page message is enabled, but the false positive page is empty
	 */
	public function testValidateWhenTalkPageEnabledNoFalsePositivePage() {
		$validator = new AutoModeratorConfigValidation();

		$result = $validator->validate( [
			'AutoModeratorRevertTalkPageMessageEnabled' => true,
			'AutoModeratorFalsePositivePageTitle' => null
		] );
		$this->assertEquals( StatusValue::newFatal(
			'automoderator-config-validator-add-false-positive-page-talk-page-msg-enabled',
			true
		), $result );
	}

	/**
	 * @covers ::validate when the talk page message is enabled, but the false positive page is empty
	 */
	public function testValidateWhenTalkPageEnabledNoFalsePositivePageMultilingual() {
		$validator = new AutoModeratorConfigValidation();

		$result = $validator->validate( [
			'AutoModeratorMultilingualConfigRevertTalkPageMessageEnabled' => true,
			'AutoModeratorMultilingualConfigFalsePositivePageTitle' => null
		] );
		$this->assertEquals( StatusValue::newFatal(
			'automoderator-config-validator-multilingual-add-false-positive-page-talk-page-msg-enabled',
			true
		), $result );
	}

	/**
	 * @covers ::validate when the talk page message is enabled, but the false positive page is empty
	 */
	public function testValidateWhenFalsePositivePageNotExists() {
		$validator = new AutoModeratorConfigValidation();
		$mockTitle = $this->createMock( Title::class );
		$mockTitle->method( 'exists' )->willReturn( false );
		$result = $validator->validate( [
			'AutoModeratorMultilingualConfigFalsePositivePageTitle' => 'Does not exist'
		] );
		$this->assertEquals( StatusValue::newFatal(
			'automoderator-config-validator-false-positive-page-not-exist',
			true
		), $result );
	}
}
