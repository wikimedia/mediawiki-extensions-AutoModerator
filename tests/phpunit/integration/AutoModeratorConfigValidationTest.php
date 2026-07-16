<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\AutoModerator\Tests;

use MediaWiki\Extension\AutoModerator\Config\Validation\AutoModeratorConfigSchema;
use MediaWiki\Extension\AutoModerator\Config\Validation\AutoModeratorConfigValidation;
use MediaWiki\Extension\AutoModerator\Config\Validation\AutoModeratorMultilingualConfigSchema;

/**
 * @covers \MediaWiki\Extension\AutoModerator\Config\Validation\AutoModeratorConfigValidation
 * @group Database
 */
class AutoModeratorConfigValidationTest extends \MediaWikiIntegrationTestCase {
	private array $config;
	private array $multilingualConfig;

	protected function setUp(): void {
		$this->markTestSkippedIfExtensionNotLoaded( 'CommunityConfiguration' );
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
	}

	private function validate( $class ) {
		// CC validators expect objects
		$config = (object)match ( $class ) {
			AutoModeratorConfigSchema::class => $this->config,
			AutoModeratorMultilingualConfigSchema::class => $this->multilingualConfig,
		};
		$services = $this->getServiceContainer();
		$validator = AutoModeratorConfigValidation::factory(
			$services->getService( 'CommunityConfiguration.ValidatorFactory' ),
			$services->getPermissionManager(),
			$class
		);
		return $validator->validateStrictly( $config );
	}

	/**
	 * When user right valid.
	 */
	public function testValidateWhenUserRightValid() {
		$this->config[ 'AutoModeratorSkipUserRights' ] = [ 'bot' ];
		$result = $this->validate( AutoModeratorConfigSchema::class );
		$this->assertStatusGood( $result );
	}

	/**
	 * When user right invalid.
	 */
	public function testValidateWhenUserRightInvalid() {
		$this->config[ 'AutoModeratorSkipUserRights' ] = [ 'bot-2' ];
		$result = $this->validate( AutoModeratorConfigSchema::class );
		$this->assertStatusNotGood( $result );
		$errors = $result->getErrors();
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
		$this->assertSame( $expected, $errors );
	}

	/**
	 * When user reverts per page not a number.
	 */
	public function testValidateWhenUserRevertPerPageNotANumber() {
		$this->config[ 'AutoModeratorUserRevertsPerPage' ] = 'not a number';
		$result = $this->validate( AutoModeratorConfigSchema::class );
		$this->assertStatusNotGood( $result );
		$errors = $result->getErrors();
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
		$this->assertSame( $expected, $errors );
	}

	/**
	 * When user reverts per page not set.
	 */
	public function testValidateWhenUserRevertPerPageNotSet() {
		unset( $this->config[ 'AutoModeratorUserRevertsPerPage' ] );
		$result = $this->validate( AutoModeratorConfigSchema::class );
		$this->assertStatusGood( $result );
	}

	/**
	 * When user reverts per page not set.
	 */
	public function testValidateWhenUserRevertPerPageNotSetEmptyString() {
		$this->config[ 'AutoModeratorUserRevertsPerPage' ] = '';
		$result = $this->validate( AutoModeratorConfigSchema::class );
		$this->assertStatusGood( $result );
	}

	/**
	 * When user reverts per page is a number.
	 */
	public function testValidateWhenUserRevertPerPageIsANumber() {
		$this->config[ 'AutoModeratorUserRevertsPerPage' ] = '25';
		$result = $this->validate( AutoModeratorConfigSchema::class );
		$this->assertStatusGood( $result );
	}

	/**
	 * When multilingual threshold is a number.
	 */
	public function testValidateWhenMultilingualThresholdIsANumber() {
		$this->multilingualConfig[ 'AutoModeratorMultilingualConfigMultilingualThreshold' ] = '0.992';
		$this->multilingualConfig[ 'AutoModeratorMultilingualConfigEnableMultilingual' ] = true;
		$result = $this->validate( AutoModeratorMultilingualConfigSchema::class );
		$this->assertStatusGood( $result );
	}

	/**
	 * When multilingual threshold is not a number.
	 */
	public function testValidateWhenMultilingualThresholdIsNotANumber() {
		$this->multilingualConfig[ 'AutoModeratorMultilingualConfigMultilingualThreshold' ] = 'oopsie';
		$this->multilingualConfig[ 'AutoModeratorMultilingualConfigEnableMultilingual' ] = true;
		$result = $this->validate( AutoModeratorMultilingualConfigSchema::class );
		$this->assertStatusNotGood( $result );
		$errors = $result->getErrors();
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
		$this->assertSame( $expected, $errors );
	}

	/**
	 * When multilingual threshold is within range.
	 */
	public function testValidateWhenMultilingualThresholdIsWithinRange() {
		$this->multilingualConfig[ 'AutoModeratorMultilingualConfigMultilingualThreshold' ] = '0.992';
		$this->multilingualConfig[ 'AutoModeratorMultilingualConfigEnableMultilingual' ] = true;
		$result = $this->validate( AutoModeratorMultilingualConfigSchema::class );
		$this->assertStatusGood( $result );
	}

	/**
	 * When multilingual threshold is out of range.
	 */
	public function testValidateWhenMultilingualThresholdIsNotWithinRange() {
		$this->multilingualConfig[ 'AutoModeratorMultilingualConfigMultilingualThreshold' ] = '0.001';
		$this->multilingualConfig[ 'AutoModeratorMultilingualConfigEnableMultilingual' ] = true;
		$result = $this->validate( AutoModeratorMultilingualConfigSchema::class );
		$this->assertStatusNotGood( $result );
		$errors = $result->getErrors();
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
		$this->assertSame( $expected, $errors );
	}

	/**
	 * When multilingual threshold is added but the model is not enabled.
	 */
	public function testValidateWhenMultilingualThresholdAddedModelNotEnabled() {
		$this->multilingualConfig[ 'AutoModeratorMultilingualConfigMultilingualThreshold' ] = '0.967';
		unset( $this->multilingualConfig[ 'AutoModeratorMultilingualConfigEnableMultilingual' ] );
		$result = $this->validate( AutoModeratorMultilingualConfigSchema::class );
		$this->assertStatusNotGood( $result );
		$errors = $result->getErrors();
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
		$this->assertSame( $expected, $errors );
	}

	/**
	 * When the multilingual and the language-agnostic models are both enabled.
	 */
	public function testValidateWhenMultilingualModelAndLanguageAgnosticEnabled() {
		$this->multilingualConfig[ 'AutoModeratorMultilingualConfigEnableLanguageAgnostic' ] = true;
		$this->multilingualConfig[ 'AutoModeratorMultilingualConfigEnableMultilingual' ] = true;
		$result = $this->validate( AutoModeratorMultilingualConfigSchema::class );
		$this->assertStatusNotGood( $result );
		$errors = $result->getErrors();
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
		$this->assertSame( $expected, $errors );
	}

	/**
	 * When the talk page message is enabled, but the false positive page is empty.
	 */
	public function testValidateWhenTalkPageEnabledNoFalsePositivePage() {
		$this->config[ 'AutoModeratorRevertTalkPageMessageEnabled' ] = true;
		$this->config[ 'AutoModeratorFalsePositivePageTitle' ] = '';
		$result = $this->validate( AutoModeratorConfigSchema::class );
		$this->assertStatusNotGood( $result );
		$errors = $result->getErrors();
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
		$this->assertSame( $expected, $errors );
	}

	/**
	 * When the talk page message is enabled, but the false positive page is empty.
	 */
	public function testValidateWhenTalkPageEnabledNoFalsePositivePageMultilingual() {
		$this->multilingualConfig[ 'AutoModeratorMultilingualConfigRevertTalkPageMessageEnabled' ] = true;
		$this->multilingualConfig[ 'AutoModeratorMultilingualConfigFalsePositivePageTitle' ] = '';
		$result = $this->validate( AutoModeratorMultilingualConfigSchema::class );
		$this->assertStatusNotGood( $result );
		$errors = $result->getErrors();
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
		$this->assertSame( $expected, $errors );
	}
}
