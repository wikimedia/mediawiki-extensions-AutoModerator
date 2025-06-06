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
		'AutoModeratorConfig.json' => AutoModeratorConfigValidation::class,
		'AutoModeratorMultilingualConfig.json' => AutoModeratorConfigValidation::class
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
	 * Code helper for comparing titles
	 *
	 * @param Title $configTitle
	 * @param string $otherConfigPage
	 * @return bool
	 */
	private function titleEquals( Title $configTitle, string $otherConfigPage ): bool {
		$varTitle = $this->titleFactory
			->makeTitleSafe( NS_MEDIAWIKI, $otherConfigPage );
		return $varTitle !== null && $configTitle->equals( $varTitle );
	}

	/**
	 * Return list of supported config pages
	 *
	 * @return Title[]
	 */
	public function getSupportedConfigPages(): array {
		return array_filter(
			array_map(
				function ( string $var ) {
					return $this->titleFactory->makeTitleSafe(
						NS_MEDIAWIKI,
						$var
					);
				},
				array_keys( self::CONFIG_VALIDATOR_MAP )
			)
		);
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
	 * @throws InvalidArgumentException when passed config page is not recognized; this should
	 * never happen in practice.
	 */
	public function newConfigValidator( LinkTarget $configPage ): IConfigValidator {
		$title = $this->titleFactory->newFromLinkTarget( $configPage );

		foreach ( self::CONFIG_VALIDATOR_MAP as $var => $validatorClass ) {
			if ( $this->titleEquals( $title, $var ) ) {
				return $this->constructValidator( $validatorClass );
			}
		}

		throw new InvalidArgumentException( 'Unsupported config page' );
	}
}
