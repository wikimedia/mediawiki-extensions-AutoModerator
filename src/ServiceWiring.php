<?php

use AutoModerator\AutoModeratorServices;
use AutoModerator\TalkPageMessageSender;
use MediaWiki\Config\Config;
use MediaWiki\MediaWikiServices;

/**
 * @codeCoverageIgnore
 * @phpcs-require-sorted-array
 */
return [

	'AutoModeratorConfig' => static function ( MediaWikiServices $services ): Config {
		return $services->getService( 'CommunityConfiguration.MediaWikiConfigRouter' );
	},

	'AutoModeratorTalkPageMessageSender' => static function ( MediaWikiServices $services ) {
		$autoModeratorServices = AutoModeratorServices::wrap( $services );
		return new TalkPageMessageSender(
			$services->getRevisionStore(),
			$autoModeratorServices->getAutoModeratorConfig(),
			$services->getJobQueueGroup(),
			$services->getTitleFactory()
		);
	},

];
