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
		if ( $fieldName == "AutoModeratorSkipUserRights" ) {
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
		if ( $fieldName == "AutoModeratorUserRevertsPerPage" && $value && !is_numeric( $value ) ) {
			return StatusValue::newFatal(
				'automoderator-config-validator-user-reverts-per-page-not-number',
				$value
			);
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
	 */
	public function getDefaultContent(): array {
		return [];
	}
}
