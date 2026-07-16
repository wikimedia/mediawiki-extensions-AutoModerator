<?php

declare( strict_types = 1 );

use MediaWiki\Config\Config;
use MediaWiki\Extension\AutoModerator\AutoModeratorServices;
use MediaWiki\Extension\AutoModerator\TalkPageMessageSender;
use MediaWiki\MediaWikiServices;

/**
 * @codeCoverageIgnore
 * @phpcs-require-sorted-array
 */
return [

	'AutoModeratorConfig' => static function ( MediaWikiServices $services ): Config {
		return $services->getExtensionRegistry()->isLoaded( 'CommunityConfiguration' ) ?
			$services->getService( 'CommunityConfiguration.MediaWikiConfigRouter' ) :
			$services->getMainConfig();
	},

	'AutoModeratorTalkPageMessageSender' => static function ( MediaWikiServices $services ) {
		$autoModeratorServices = AutoModeratorServices::wrap( $services );
		return new TalkPageMessageSender(
			$services->getRevisionStore(),
			$autoModeratorServices->getAutoModeratorConfig(),
			$services->getJobQueueGroup(),
			$services->getTitleFactory(),
			$services->getContentLanguage()
		);
	},

];
