<?php

namespace AutoModerator\Config\Validation;

use AutoModerator\Config\AutoModeratorCommunityConfig;
use InvalidArgumentException;
use Message;
use StatusValue;

/**
 * Validation class for MediaWiki:AutoModeratorConfig.json
 */
class AutoModeratorConfigValidation implements IConfigValidator {
	use DatatypeValidationTrait;

	/**
	 * Copy of TemplateCollectionFeature::MAX_TEMPLATES_IN_COLLECTION. We avoid a direct reference
	 * to keep CirrusSearch an optional dependency.
	 */
	public const MAX_TEMPLATES_IN_COLLECTION = 800;

	private function getConfigDescriptors(): array {
		return [
			'AutoModeratorEnable' => [
				'type' => 'bool',
			],
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
		if ( !in_array( $variable, AutoModeratorCommunityConfig::ALLOW_LIST ) ) {
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
