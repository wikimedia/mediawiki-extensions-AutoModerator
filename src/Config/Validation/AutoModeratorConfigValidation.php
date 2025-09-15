<?php

namespace AutoModerator\Config\Validation;

use AutoModerator\Config\AutoModeratorWikiConfigLoader;
use InvalidArgumentException;
use MediaWiki\MediaWikiServices;
use MediaWiki\Message\Message;
use StatusValue;

/**
 * Validation class for MediaWiki:AutoModeratorConfig.json
 */
class AutoModeratorConfigValidation implements IConfigValidator {
	use DatatypeValidationTrait;

	/**
	 * @codeCoverageIgnore
	 */
	private function getConfigDescriptors(): array {
		return [
			'AutoModeratorEnableRevisionCheck' => [
				'type' => 'bool',
			],
			'AutoModeratorFalsePositivePageTitle' => [
				'type' => '?string',
			],
			'AutoModeratorUseEditFlagMinor' => [
				'type' => 'bool',
			],
			'AutoModeratorRevertTalkPageMessageEnabled' => [
				'type' => 'bool',
			],
			'AutoModeratorEnableBotFlag' => [
				'type' => 'bool',
			],
			'AutoModeratorSkipUserRights' => [
				'type' => 'array'
			],
			'AutoModeratorCautionLevel' => [
				'type' => 'string',
			],
			'AutoModeratorEnableUserRevertsPerPage' => [
				'type' => 'bool',
			],
			'AutoModeratorUserRevertsPerPage' => [
				'type' => '?string',
			],
			'AutoModeratorHelpPageLink' => [
				'type' => '?string'
			],
			'AutoModeratorMultilingualConfigEnableRevisionCheck' => [
				'type' => 'bool',
			],
			'AutoModeratorMultilingualConfigFalsePositivePageTitle' => [
				'type' => '?string',
			],
			'AutoModeratorMultilingualConfigUseEditFlagMinor' => [
				'type' => 'bool',
			],
			'AutoModeratorMultilingualConfigRevertTalkPageMessageEnabled' => [
				'type' => 'bool',
			],
			'AutoModeratorMultilingualConfigEnableBotFlag' => [
				'type' => 'bool',
			],
			'AutoModeratorMultilingualConfigSkipUserRights' => [
				'type' => 'array'
			],
			'AutoModeratorMultilingualConfigCautionLevel' => [
				'type' => 'string',
			],
			'AutoModeratorMultilingualConfigEnableUserRevertsPerPage' => [
				'type' => 'bool',
			],
			'AutoModeratorMultilingualConfigUserRevertsPerPage' => [
				'type' => '?string',
			],
			'AutoModeratorMultilingualConfigHelpPageLink' => [
				'type' => '?string'
			],
			'AutoModeratorMultilingualConfigEnableLanguageAgnostic' => [
				'type' => 'bool'
			],
			'AutoModeratorMultilingualConfigEnableMultilingual' => [
				'type' => 'bool'
			],
			'AutoModeratorMultilingualConfigMultilingualThreshold' => [
				'type' => '?string',
			]
		];
	}

	/**
	 * Validate a given field
	 *
	 * @param string $fieldName Name of the field to be validated
	 * @param array $descriptor Descriptor of the field (
	 * @param array $data
	 * @return StatusValue
	 */
	private function validateField(
		string $fieldName,
		array $descriptor,
		array $data
	): StatusValue {
		// validate is supposed to make sure $data has $field as a key,
		// so this should not throw key errors.
		$value = $data[$fieldName];

		$expectedType = $descriptor['type'];
		if ( !$this->validateFieldDatatype( $expectedType, $value ) ) {
			return StatusValue::newFatal(
				'automoderator-config-validator-datatype-mismatch',
				$fieldName,
				$expectedType,
				gettype( $value )
			);
		}

		if ( isset( $descriptor['maxSize'] ) && count( $value ) > $descriptor['maxSize'] ) {
			return StatusValue::newFatal(
				'automoderator-config-validator-array-toobig',
				$fieldName,
				Message::numParam( $descriptor['maxSize'] )
			);
		}

		$isUserRightsField = $fieldName == "AutoModeratorSkipUserRights" ||
			$fieldName == "AutoModeratorMultilingualConfigSkipUserRights";
		if ( $isUserRightsField ) {
			$allPermissions = MediaWikiServices::getInstance()->getPermissionManager()->getAllPermissions();
			foreach ( $value as $userRight ) {
				if ( !in_array( $userRight, $allPermissions ) ) {
					return StatusValue::newFatal(
						'automoderator-config-validator-userrights-not-allowed',
						$userRight
					);
				}
			}
		}

		$isUserRevertsPerPageField = $fieldName == "AutoModeratorUserRevertsPerPage" ||
			$fieldName == "AutoModeratorMultilingualConfigUserRevertsPerPage";
		if ( $isUserRevertsPerPageField && $value && !is_numeric( $value ) ) {
			return StatusValue::newFatal(
				'automoderator-config-validator-user-reverts-per-page-not-number',
				$value
			);
		}

		if ( $fieldName == "AutoModeratorMultilingualConfigMultilingualThreshold" && $value && !is_numeric( $value ) ) {
			return StatusValue::newFatal(
				'automoderator-config-validator-multilingual-threshold-not-number',
				$value
			);
		}

		if ( $fieldName == "AutoModeratorMultilingualConfigMultilingualThreshold" &&
			$data['AutoModeratorMultilingualConfigEnableMultilingual'] &&
			( $value < 0.850 || $value > 0.999 ) ) {
			return StatusValue::newFatal(
				'automoderator-config-validator-multilingual-threshold-value-outside-range',
				$value
			);
		}
		if ( $fieldName == "AutoModeratorMultilingualConfigMultilingualThreshold" && $value &&
			!$data['AutoModeratorMultilingualConfigEnableMultilingual'] ) {
			return StatusValue::newFatal(
				'automoderator-config-validator-multilingual-threshold-multilingual-not-enabled',
				$value
			);
		}

		if ( $fieldName == "AutoModeratorMultilingualConfigEnableLanguageAgnostic" && $value
			&& $data['AutoModeratorMultilingualConfigEnableMultilingual'] ) {
			return StatusValue::newFatal(
				'automoderator-config-validator-multilingual-select-only-one-model',
				$value
			);
		}

		if ( $fieldName == "AutoModeratorMultilingualConfigRevertTalkPageMessageEnabled" && $value
			&& !$data['AutoModeratorMultilingualConfigFalsePositivePageTitle'] ) {
			return StatusValue::newFatal(
				'automoderator-config-validator-multilingual-add-false-positive-page-talk-page-msg-enabled',
				$value
			);
		}

		if ( $fieldName == "AutoModeratorRevertTalkPageMessageEnabled" && $value
			&& !$data['AutoModeratorFalsePositivePageTitle'] ) {
			return StatusValue::newFatal(
				'automoderator-config-validator-add-false-positive-page-talk-page-msg-enabled',
				$value
			);
		}

		$isFalsePositivePageTitle = $fieldName == 'AutoModeratorFalsePositivePageTitle' ||
			$fieldName == 'AutoModeratorMultilingualConfigFalsePositivePageTitle';
		if ( $isFalsePositivePageTitle && $value ) {
			$titleFactory = MediaWikiServices::getInstance()->getTitleFactory();
			$title = $titleFactory->newFromText( $value );
			if ( !$title?->exists() ) {
				return StatusValue::newFatal(
					'automoderator-config-validator-false-positive-page-not-exist',
					$value
				);
			}
		}

		return StatusValue::newGood();
	}

	/**
	 * @inheritDoc
	 */
	public function validate( array $data ): StatusValue {
		$status = StatusValue::newGood();
		foreach ( $this->getConfigDescriptors() as $field => $descriptor ) {
			if ( !array_key_exists( $field, $data ) ) {
				// No need to validate something we're not setting
				continue;
			}

			$status->merge( $this->validateField( $field, $descriptor, $data ) );
		}

		return $status;
	}

	/**
	 * @inheritDoc
	 * @codeCoverageIgnore
	 */
	public function validateVariable( string $variable, $value ): void {
		if ( !in_array( $variable, AutoModeratorWikiConfigLoader::ALLOW_LIST ) ) {
			throw new InvalidArgumentException(
				'Invalid attempt to set a variable via WikiPageConfigWriter'
			);
		}
	}

	/**
	 * @inheritDoc
	 * @codeCoverageIgnore
	 */
	public function getDefaultContent(): array {
		return [];
	}
}
