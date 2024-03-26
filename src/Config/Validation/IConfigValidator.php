<?php

namespace AutoModerator\Config\Validation;

use StatusValue;

interface IConfigValidator {
	/**
	 * Validate passed config
	 *
	 * This is executed by ConfigHooks for manual edits and by
	 * WikiPageConfigLoader before returning the config
	 * (this is to ensure invalid config is never used).
	 *
	 * @param array $config Associative array representing config that's going to be validated
	 * @return StatusValue
	 */
	public function validate( array $config ): StatusValue;

	/**
	 * If the configuration page assigned to this validator does not exist, return this
	 *
	 * Useful for ie. structured mentor list, which requires the Mentors key
	 * to be present.
	 *
	 * @return array
	 */
	public function getDefaultContent(): array;
}
