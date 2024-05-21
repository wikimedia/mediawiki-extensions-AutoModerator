<?php

namespace AutoModerator\Config\Validation;

use InvalidArgumentException;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;

class ConfigValidatorFactory {
	private TitleFactory $titleFactory;

	/**
	 * @var string[]
	 *
	 * Maps variable to validator class.
	 *
	 * @note When adding a mapping, add an entry to ConfigValidatorFactory::constructValidator
	 * as well.
	 */
	private const CONFIG_VALIDATOR_MAP = [
		'AutoModeratorWikiConfigPageTitle' => AutoModeratorConfigValidation::class
	];

	/**
	 * @param TitleFactory $titleFactory
	 */
	public function __construct(
		TitleFactory $titleFactory
	) {
		$this->titleFactory = $titleFactory;
	}

	/**
	 * Return list of supported config pages
	 *
	 * @return Title[]
	 */
	public function getSupportedConfigPages(): array {
		// Update this when CONFIG_VALIDATOR_MAP has another entry
		$title = $this->titleFactory->makeTitle(
		 NS_MEDIAWIKI,
		 'AutoModeratorConfig.js'
		);
		return [ $title ];
	}

	/**
	 * Construct given validator
	 *
	 * @param string $class A ::class constant from one of the validators
	 * @return IConfigValidator
	 * @throws InvalidArgumentException when passed class is not supported; this should never
	 * happen in practice.
	 */
	private function constructValidator( string $class ): IConfigValidator {
		switch ( $class ) {
			case AutoModeratorConfigValidation::class:
				return new AutoModeratorConfigValidation();
			case NoValidationValidator::class:
				return new NoValidationValidator();
			default:
				throw new InvalidArgumentException( 'Unsupported config class' );
		}
	}

	/**
	 * Generate a validator for a config page
	 *
	 * @param LinkTarget $configPage
	 * @return IConfigValidator
	 * @throws InvalidArgumentException when no config page is passed; this should
	 * never happen in practice.
	 */
	public function newConfigValidator( LinkTarget $configPage ): IConfigValidator {
		$title = $this->titleFactory->newFromLinkTarget( $configPage );

		$validatorClass = array_values( self::CONFIG_VALIDATOR_MAP )[ 0 ];
		return $this->constructValidator( $validatorClass );
	}
}
