<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\AutoModerator\Hooks;

use MediaWiki\Config\Config;
use MediaWiki\Extension\CommunityConfiguration\Hooks\CommunityConfigurationProvider_initListHook;

readonly class CommunityConfigurationProviderHookHandler implements CommunityConfigurationProvider_initListHook {

	public function __construct(
		private Config $config,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function onCommunityConfigurationProvider_initList( array &$providers ): void {
		if (
			$this->config->has( 'AutoModeratorMultiLingualRevertRisk' ) &&
			$this->config->get( 'AutoModeratorMultiLingualRevertRisk' )
		) {
			unset( $providers['AutoModerator'] );
		} else {
			unset( $providers['AutomoderatorMultilingual'] );
		}
	}

}
