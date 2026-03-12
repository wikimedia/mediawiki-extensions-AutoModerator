<?php

namespace AutoModerator\Tests;

use AutoModerator\Config\Validation\AutoModeratorConfigSchema;
use AutoModerator\Config\Validation\AutoModeratorConfigValidation;
use AutoModerator\Config\Validation\AutoModeratorMultilingualConfigSchema;
use MediaWiki\Extension\CommunityConfiguration\Validation\ValidationStatus;
use MediaWiki\Extension\CommunityConfiguration\Validation\ValidatorFactory;

/**
 * @coversDefaultClass \AutoModerator\Config\Validation\AutoModeratorConfigValidation
 * @group Database
 */
class AutoModeratorConfigValidationTest extends \MediaWikiIntegrationTestCase {
	private array $config;
	private array $multilingualConfig;
	private ValidatorFactory $validatorFactory;

	protected function setUp(): void {
		$services = $this->getServiceContainer();
		$this->config = [
			'AutoModeratorEnableRevisionCheck' => false,
			'AutoModeratorFalsePositivePageTitle' => '',
			'AutoModeratorUseEditFlagMinor' => false,
			'AutoModeratorRevertTalkPageMessageEnabled' => false,
			'AutoModeratorRevertTalkPageMessageRegisteredUsersOnly' => false,
			'AutoModeratorEnableBotFlag' => false,
			'AutoModeratorSkipUserRights' => [
				'bot',
				'autopatrol',
			],
			'AutoModeratorCautionLevel' => "very-cautious",
			'AutoModeratorEnableUserRevertsPerPage' => false,
			'AutoModeratorUserRevertsPerPage' => '',
			'AutoModeratorHelpPageLink' => '',
		];
		$this->multilingualConfig = [
			'AutoModeratorMultilingualConfigEnableRevisionCheck' => false,
			'AutoModeratorMultilingualConfigFalsePositivePageTitle' => '',
			'AutoModeratorMultilingualConfigUseEditFlagMinor' => false,
			'AutoModeratorMultilingualConfigRevertTalkPageMessageEnabled' => false,
			'AutoModeratorMultilingualConfigRevertTalkPageMessageRegisteredUsersOnly' => false,
			'AutoModeratorMultilingualConfigEnableBotFlag' => false,
			'AutoModeratorMultilingualConfigSkipUserRights' => [
				'bot',
				'autopatrol',
			],
			'AutoModeratorMultilingualConfigCautionLevel' => 'very-cautious',
			'AutoModeratorMultilingualConfigEnableUserRevertsPerPage' => false,
			'AutoModeratorMultilingualConfigUserRevertsPerPage' => '',
			'AutoModeratorMultilingualConfigHelpPageLink' => '',
			'AutoModeratorMultilingualConfigEnableMultilingual' => false,
			'AutoModeratorMultilingualConfigEnableLanguageAgnostic' => false,
		];
		$this->validatorFactory = $services->getService( 'CommunityConfiguration.ValidatorFactory' );
	}

	private function validate( $class ) {
		switch ( $class ) {
			case 'AutoModerator\Config\Validation\AutoModeratorConfigSchema':
				$config = (object)$this->config;
				break;
			case 'AutoModerator\Config\Validation\AutoModeratorMultilingualConfigSchema':
				$config = (object)$this->multilingualConfig;
				$this->overrideConfigValue( 'AutoModeratorMultiLingualRevertRisk', true );
				break;

		}
		// CC validators expect objects
		$validator = AutoModeratorConfigValidation::factory( $this->validatorFactory, $config, $class );
		return $validator->validateStrictly( $config );
	}

	/**
	 * @covers ::validateStrictly when user right valid
	 * @covers ::validateStrictly
	 */
	public function testValidateWhenUserRightValid() {
		$this->config[ 'AutoModeratorSkipUserRights' ] = [ 'bot' ];
		$result = $this->validate( AutoModeratorConfigSchema::class );
		$this->assertEquals( ValidationStatus::newGood(), $result );
	}

	/**
	 * @covers ::validateStrictly when user right invalid
	 * @covers ::validateStrictly
	 */
	public function testValidateWhenUserRightInvalid() {
		$this->config[ 'AutoModeratorSkipUserRights' ] = [ 'bot-2' ];
		$result = $this->validate( AutoModeratorConfigSchema::class );
		$this->assertFalse( $result->isGood() );
		$errors = $result->getErrors();
		$this->assertCount( 1, $errors );
		$expected = [
			[
				'type' => 'error',
				'message' => 'communityconfiguration-schema-validation-error',
				'params' => [
					'AutoModeratorSkipUserRights',
					'User right bot-2 does not exist. Please try another.',
				],
			],
		];
		$this->assertEquals( $expected, $errors );
	}

	/**
	 * @covers ::validateStrictly when user reverts per page not a number
	 * @covers ::validateStrictly
	 */
	public function testValidateWhenUserRevertPerPageNotANumber() {
		$this->config[ 'AutoModeratorUserRevertsPerPage' ] = 'not a number';
		$result = $this->validate( AutoModeratorConfigSchema::class );
		$this->assertFalse( $result->isGood() );
		$errors = $result->getErrors();
		$this->assertCount( 1, $errors );
		$expected = [
			[
				'type' => 'error',
				'message' => 'communityconfiguration-schema-validation-error',
				'params' => [
					'AutoModeratorUserRevertsPerPage',
					'The value entered for User reverts per page is not a number, please try another.',
				],
			],
		];
		$this->assertEquals( $expected, $errors );
	}

	/**
	 * @covers ::validateStrictly when user reverts per page not set
	 * @covers ::validateStrictly
	 */
	public function testValidateWhenUserRevertPerPageNotSet() {
		unset( $this->config[ 'AutoModeratorUserRevertsPerPage' ] );
		$result = $this->validate( AutoModeratorConfigSchema::class );
		$this->assertEquals( ValidationStatus::newGood(), $result );
	}

	/**
	 * @covers ::validateStrictly when user reverts per page not set
	 * @covers ::validateStrictly
	 */
	public function testValidateWhenUserRevertPerPageNotSetEmptyString() {
		$this->config[ 'AutoModeratorUserRevertsPerPage' ] = '';
		$result = $this->validate( AutoModeratorConfigSchema::class );
		$this->assertEquals( ValidationStatus::newGood(), $result );
	}

	/**
	 * @covers ::validateStrictly when user reverts per page is a number
	 * @covers ::validateStrictly
	 */
	public function testValidateWhenUserRevertPerPageIsANumber() {
		$this->config[ 'AutoModeratorUserRevertsPerPage' ] = '25';
		$result = $this->validate( AutoModeratorConfigSchema::class );
		$this->assertEquals( ValidationStatus::newGood(), $result );
	}

	/**
	 * @covers ::validateStrictly when multilingual threshold is a number
	 * @covers ::validateStrictly
	 */
	public function testValidateWhenMultilingualThresholdIsANumber() {
		$this->multilingualConfig[ 'AutoModeratorMultilingualConfigMultilingualThreshold' ] = '0.992';
		$this->multilingualConfig[ 'AutoModeratorMultilingualConfigEnableMultilingual' ] = true;
		$result = $this->validate( AutoModeratorMultilingualConfigSchema::class );
		$this->assertEquals( ValidationStatus::newGood(), $result );
	}

	/**
	 * @covers ::validateStrictly when multilingual threshold is not a number
	 * @covers ::validateStrictly
	 */
	public function testValidateWhenMultilingualThresholdIsNotANumber() {
		$this->multilingualConfig[ 'AutoModeratorMultilingualConfigMultilingualThreshold' ] = 'oopsie';
		$this->multilingualConfig[ 'AutoModeratorMultilingualConfigEnableMultilingual' ] = true;
		$result = $this->validate( AutoModeratorMultilingualConfigSchema::class );
		$this->assertFalse( $result->isGood() );
		$errors = $result->getErrors();
		$this->assertCount( 2, $errors );
		$expected = [
			[
				'type' => 'error',
				'message' => 'communityconfiguration-schema-validation-error',
				'params' => [
					'AutoModeratorMultilingualConfigMultilingualThreshold',
					'The multilingual threshold must be a number.',
				],
			],
			[
				'type' => 'error',
				'message' => 'communityconfiguration-schema-validation-error',
				'params' => [
					'AutoModeratorMultilingualConfigMultilingualThreshold',
					'The threshold input is outside the range. Please input a value that is between 0.850 and 0.999',
				],
			],
		];
		$this->assertEquals( $expected, $errors );
	}

	/**
	 * @covers ::validateStrictly when multilingual threshold is within range
	 * @covers ::validateStrictly
	 */
	public function testValidateWhenMultilingualThresholdIsWithinRange() {
		$this->multilingualConfig[ 'AutoModeratorMultilingualConfigMultilingualThreshold' ] = '0.992';
		$this->multilingualConfig[ 'AutoModeratorMultilingualConfigEnableMultilingual' ] = true;
		$result = $this->validate( AutoModeratorMultilingualConfigSchema::class );
		$this->assertEquals( ValidationStatus::newGood(), $result );
	}

	/**
	 * @covers ::validateStrictly when multilingual threshold is out of range
	 */
	public function testValidateWhenMultilingualThresholdIsNotWithinRange() {
		$this->multilingualConfig[ 'AutoModeratorMultilingualConfigMultilingualThreshold' ] = '0.001';
		$this->multilingualConfig[ 'AutoModeratorMultilingualConfigEnableMultilingual' ] = true;
		$result = $this->validate( AutoModeratorMultilingualConfigSchema::class );
		$this->assertFalse( $result->isGood() );
		$errors = $result->getErrors();
		$this->assertCount( 1, $errors );
		$expected = [
			[
				'type' => 'error',
				'message' => 'communityconfiguration-schema-validation-error',
				'params' => [
					'AutoModeratorMultilingualConfigMultilingualThreshold',
					'The threshold input is outside the range. Please input a value that is between 0.850 and 0.999',
				],
			],
		];
		$this->assertEquals( $expected, $errors );
	}

	/**
	 * @covers ::validateStrictly when multilingual threshold is added but the model is not enabled
	 * @covers ::validateStrictly
	 */
	public function testValidateWhenMultilingualThresholdAddedModelNotEnabled() {
		$this->multilingualConfig[ 'AutoModeratorMultilingualConfigMultilingualThreshold' ] = '0.967';
		unset( $this->multilingualConfig[ 'AutoModeratorMultilingualConfigEnableMultilingual' ] );
		$result = $this->validate( AutoModeratorMultilingualConfigSchema::class );
		$this->assertFalse( $result->isGood() );
		$errors = $result->getErrors();
		$this->assertCount( 1, $errors );
		$expected = [
			[
				'type' => 'error',
				'message' => 'communityconfiguration-schema-validation-error',
				'params' => [
					'AutoModeratorMultilingualConfigMultilingualThreshold',
					// phpcs:ignore
					'The multilingual model was not enabled, but a threshold was set. Please enable the multilingual model.',
				],
			],
		];
		$this->assertEquals( $expected, $errors );
	}

	/**
	 * @covers ::validateStrictly when the multilingual and the language-agnostic models are both enabled
	 */
	public function testValidateWhenMultilingualModelAndLanguageAgnosticEnabled() {
		$this->multilingualConfig[ 'AutoModeratorMultilingualConfigEnableLanguageAgnostic' ] = true;
		$this->multilingualConfig[ 'AutoModeratorMultilingualConfigEnableMultilingual' ] = true;
		$result = $this->validate( AutoModeratorMultilingualConfigSchema::class );
		$this->assertFalse( $result->isGood() );
		$errors = $result->getErrors();
		$this->assertCount( 1, $errors );
		$expected = [
			[
				'type' => 'error',
				'message' => 'communityconfiguration-schema-validation-error',
				'params' => [
					'AutoModeratorMultilingualConfigEnableLanguageAgnostic',
					// phpcs:ignore
					'Both the language-agnostic and multilingual models have been selected. Please enable only one of the models.',
				],
			],
		];
		$this->assertEquals( $expected, $errors );
	}

	/**
	 * @covers ::validateStrictly when the talk page message is enabled, but the false positive page is empty
	 */
	public function testValidateWhenTalkPageEnabledNoFalsePositivePage() {
		$this->config[ 'AutoModeratorRevertTalkPageMessageEnabled' ] = true;
		$this->config[ 'AutoModeratorFalsePositivePageTitle' ] = '';
		$result = $this->validate( AutoModeratorConfigSchema::class );
		$this->assertFalse( $result->isGood() );
		$errors = $result->getErrors();
		$this->assertCount( 1, $errors );
		$expected = [
			[
				'type' => 'error',
				'message' => 'communityconfiguration-schema-validation-error',
				'params' => [
					'AutoModeratorRevertTalkPageMessageEnabled',
					'You need to add a false positive reporting page when the talk page message is enabled',
				],
			],
		];
		$this->assertEquals( $expected, $errors );
	}

	/**
	 * @covers ::validateStrictly when the talk page message is enabled, but the false positive page is empty
	 */
	public function testValidateWhenTalkPageEnabledNoFalsePositivePageMultilingual() {
		$this->multilingualConfig[ 'AutoModeratorMultilingualConfigRevertTalkPageMessageEnabled' ] = true;
		$this->multilingualConfig[ 'AutoModeratorMultilingualConfigFalsePositivePageTitle' ] = '';
		$result = $this->validate( AutoModeratorMultilingualConfigSchema::class );
		$this->assertFalse( $result->isGood() );
		$errors = $result->getErrors();
		$this->assertCount( 1, $errors );
		$expected = [
			[
				'type' => 'error',
				'message' => 'communityconfiguration-schema-validation-error',
				'params' => [
					'AutoModeratorMultilingualConfigRevertTalkPageMessageEnabled',
					'You need to add a false positive reporting page when the talk page message is enabled',
				],
			],
		];
		$this->assertEquals( $expected, $errors );
	}
}
