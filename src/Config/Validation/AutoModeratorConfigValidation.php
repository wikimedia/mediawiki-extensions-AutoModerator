<?php
declare( strict_types = 1 );
namespace AutoModerator\Config\Validation;

use Iterator;
use MediaWiki\Context\IContextSource;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\CommunityConfiguration\Schema\SchemaBuilder;
use MediaWiki\Extension\CommunityConfiguration\Validation\IValidator;
use MediaWiki\Extension\CommunityConfiguration\Validation\ValidationStatus;
use MediaWiki\Extension\CommunityConfiguration\Validation\ValidatorFactory;
use MediaWiki\MediaWikiServices;

class AutoModeratorConfigValidation implements IValidator {
	public function __construct(
		private readonly IValidator $jsonSchemaValidator,
		private readonly mixed $config,
		private readonly IContextSource $context
	) {
	}

	public static function factory(
		ValidatorFactory $validatorFactory,
		mixed $config,
		string $jsonSchema,
		?IContextSource $context = null
	): self {
		$jsonSchemaValidator = $validatorFactory->newValidator(
			'AutoModerator',
			'jsonschema',
			[ $jsonSchema ],
		);
		$context ??= RequestContext::getMain();
		return new self( $jsonSchemaValidator, $config, $context );
	}

	/** @inheritDoc */
	public function validateStrictly( mixed $config, ?string $version = null ): ValidationStatus {
		$status = new ValidationStatus();
		$data = json_decode( json_encode( $config ), true );
		foreach ( $data as $field => $value ) {
			$isUserRightsField = $field == "AutoModeratorSkipUserRights" ||
				$field == "AutoModeratorMultilingualConfigSkipUserRights";
			if ( $isUserRightsField ) {
				$allPermissions = MediaWikiServices::getInstance()->getPermissionManager()->getAllPermissions();
				foreach ( $value as $userRight ) {
					if ( !in_array( $userRight, $allPermissions ) ) {
						$status->addFatal(
							$field,
							"/$field",
							$this->context->msg(
								'automoderator-config-validator-userrights-not-allowed',
								$userRight
							)->text(),
						);
					}
				}
			}

			$isUserRevertsPerPageField = $field == "AutoModeratorUserRevertsPerPage" ||
				$field == "AutoModeratorMultilingualConfigUserRevertsPerPage";
			if ( $isUserRevertsPerPageField && $value && !is_numeric( $value ) ) {
				$status->addFatal(
					$field,
					"/$field",
					$this->context->msg( 'automoderator-config-validator-user-reverts-per-page-not-number' )->text(),
				);
			}

			if ( $field == "AutoModeratorMultilingualConfigMultilingualThreshold" &&
				$value && !is_numeric( $value ) ) {
				$status->addFatal(
					$field,
					"/$field",
					$this->context->msg( 'automoderator-config-validator-multilingual-threshold-not-number' )->text(),
				);
			}

			if ( $field == "AutoModeratorMultilingualConfigMultilingualThreshold" && $value !== '' &&
				( $value < 0.850 || $value > 0.999 ) ) {
				$status->addFatal(
					$field,
					"/$field",
					$this->context->msg(
						'automoderator-config-validator-multilingual-threshold-value-outside-range'
					)->text(),
				);
			}
			if ( $field == "AutoModeratorMultilingualConfigMultilingualThreshold" && $value &&
				(
					!array_key_exists( 'AutoModeratorMultilingualConfigEnableMultilingual', $data ) ||
					!$data['AutoModeratorMultilingualConfigEnableMultilingual']
				)
			) {
				$status->addFatal(
					$field,
					"/$field",
					$this->context->msg(
						'automoderator-config-validator-multilingual-threshold-multilingual-not-enabled'
					)->text(),
				);
			}

			if ( $field == "AutoModeratorMultilingualConfigEnableLanguageAgnostic" && $value
				&& $data['AutoModeratorMultilingualConfigEnableMultilingual'] ) {
				$status->addFatal(
					$field,
					"/$field",
					$this->context->msg(
						'automoderator-config-validator-multilingual-select-only-one-model'
					)->text(),
				);
			}

			if ( $field == "AutoModeratorMultilingualConfigRevertTalkPageMessageEnabled" && $value
				&& !$data['AutoModeratorMultilingualConfigFalsePositivePageTitle'] ) {
				$status->addFatal(
					$field,
					"/$field",
					$this->context->msg(
						'automoderator-config-validator-multilingual-add-false-positive-page-talk-page-msg-enabled'
					)->text(),
				);
			}

			if ( $field == "AutoModeratorRevertTalkPageMessageEnabled" && $value
				&& !$data['AutoModeratorFalsePositivePageTitle'] ) {
				$status->addFatal(
					$field,
					"/$field",
					$this->context->msg(
						'automoderator-config-validator-add-false-positive-page-talk-page-msg-enabled'
					)->text(),
				);
			}
		}

		// Validate the config against the JSON schema
		// This will add any validation errors to the response
		$status->merge( $this->jsonSchemaValidator->validateStrictly( $config, $version ) );
		return $status;
	}

	/** @inheritDoc */
	public function validatePermissively( $config, ?string $version = null ): ValidationStatus {
		$configArray = json_decode( json_encode( $config ), true );
		return $this->jsonSchemaValidator->validatePermissively( $config, $version );
	}

	/** @inheritDoc */
	public function areSchemasSupported(): bool {
		return $this->jsonSchemaValidator->areSchemasSupported();
	}

	/** @inheritDoc */
	public function getSchemaBuilder(): SchemaBuilder {
		return $this->jsonSchemaValidator->getSchemaBuilder();
	}

	/** @inheritDoc */
	public function getSchemaIterator(): Iterator {
		return $this->jsonSchemaValidator->getSchemaIterator();
	}

	/** @inheritDoc */
	public function getSchemaVersion(): ?string {
		return $this->jsonSchemaValidator->getSchemaVersion();
	}
}
