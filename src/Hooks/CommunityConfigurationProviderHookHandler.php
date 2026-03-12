<?php

namespace AutoModerator\Hooks;

use MediaWiki\Config\Config;
use MediaWiki\Extension\CommunityConfiguration\Hooks\CommunityConfigurationProvider_initListHook;
use MediaWiki\Title\TitleFactory;

class CommunityConfigurationProviderHookHandler implements CommunityConfigurationProvider_initListHook {

	public function __construct(
		private readonly Config $config,
		private readonly TitleFactory $titleFactory,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function onCommunityConfigurationProvider_initList( array &$providers ) {
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
