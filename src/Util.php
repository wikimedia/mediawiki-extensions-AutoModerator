<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 *
 * @file
 */

namespace AutoModerator;

use MediaWiki\Config\Config;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\User\User;
use MediaWiki\User\UserGroupManager;
use MediaWiki\WikiMap\WikiMap;
use UnexpectedValueException;

class Util {

	/**
	 * @param Config $config
	 *
	 * @return string Wiki ID used by AutoModerator.
	 */
	public static function getWikiID( $config ): string {
		$autoModeratorWikiId = $config->get( 'AutoModeratorWikiId' );
		if ( $autoModeratorWikiId ) {
			return $autoModeratorWikiId;
		}
		return WikiMap::getCurrentWikiId();
	}

	/**
	 * @return string The name that ORES uses for the language-agnostic model
	 */
	public static function getORESLanguageAgnosticModelName() {
		return 'revertrisklanguageagnostic';
	}

	/**
	 * @return string The name that ORES uses for the multilingual model
	 */
	public static function getORESMultiLingualModelName() {
		// TODO: This hasn't been added to ORES; check if the name matches the model name when it is
		return 'revertriskmultilingual';
	}

	/**
	 * Returns the revert risk model the revision will be scored from
	 * @param Config $config
	 * @return string
	 */
	public static function getRevertRiskModel( Config $config ) {
		// Check if a multilingual configuration exists and is enabled
		if ( self::isMultiLingualRevertRiskEnabled( $config ) ) {
			return self::getORESMultiLingualModelName();
		} else {
			return self::getORESLanguageAgnosticModelName();
		}
	}

	/**
	 * Get a user to perform moderation actions.
	 * @param Config $config
	 * @param UserGroupManager $userGroupManager
	 *
	 * @return User
	 */
	public static function getAutoModeratorUser( $config, $userGroupManager ): User {
		$username = $config->get( 'AutoModeratorUsername' );
		$autoModeratorUser = User::newSystemUser( $username, [ 'steal' => true ] );
		'@phan-var User $autoModeratorUser';
		if ( !$autoModeratorUser ) {
			throw new UnexpectedValueException(
				"{$username} is invalid. Please change it."
			);
		}
		// Assign the 'bot' group to the user, so that it looks like a bot
		if ( !in_array( 'bot', $userGroupManager->getUserGroups( $autoModeratorUser ) ) ) {
			$userGroupManager->addUserToGroup( $autoModeratorUser, 'bot' );
		}
		return $autoModeratorUser;
	}

	/**
	 * @param Config $config
	 * @return float An AutoModeratorRevertProbability threshold  will be chosen depending on the model
	 */
	public static function getRevertThreshold( Config $config ): float {
		if ( self::isWikiMultilingual( $config ) ) {
			return self::getMultiLingualThreshold( $config );
		} else {
			$cautionLevel = $config->get( 'AutoModeratorCautionLevel' );
			return self::getCautionLevel( $cautionLevel );
		}
	}

	/**
	 * Returns the revert risk model the revision will be scored from
	 * @param Config $config
	 * @return float
	 */
	public static function getMultiLingualThreshold( Config $config ) {
		if ( $config->get( 'AutoModeratorMultilingualConfigMultilingualThreshold' ) ) {
			return $config->get( 'AutoModeratorMultilingualConfigMultilingualThreshold' );
		}
		$cautionLevel = $config->get( 'AutoModeratorMultilingualConfigCautionLevel' );
		return self::getCautionLevel( $cautionLevel );
	}

	/**
	 * Checks if multilingual revert risk is enabled on the wiki
	 * See: https://meta.wikimedia.org/wiki/Machine_learning_models/Production/Multilingual_revert_risk#Motivation
	 * for more information
	 * @param Config $config
	 * @return bool
	 */
	public static function isWikiMultilingual( Config $config ): bool {
		return $config->has( 'AutoModeratorMultiLingualRevertRisk' ) &&
			$config->get( 'AutoModeratorMultiLingualRevertRisk' );
	}

	/**
	 * Checks if multilingual revert risk is enabled on the wiki
	 * See: https://meta.wikimedia.org/wiki/Machine_learning_models/Production/Multilingual_revert_risk#Motivation
	 * for more information
	 * @param Config $config
	 * @return bool
	 */
	public static function isMultiLingualRevertRiskEnabled( Config $config ): bool {
		return self::isWikiMultilingual( $config ) &&
			(
				$config->has( 'AutoModeratorMultilingualConfigEnableMultilingual' ) &&
				$config->get( 'AutoModeratorMultilingualConfigEnableMultilingual' )
			) && (
				!$config->has( 'AutoModeratorMultilingualConfigEnableLanguageAgnostic' ) ||
				$config->get( 'AutoModeratorMultilingualConfigEnableLanguageAgnostic' ) !== true
			);
	}

	/**
	 * @param Config $config
	 * @return mixed
	 */
	public static function getFalsePositivePageTitleText( Config $config ): mixed {
		return self::isWikiMultilingual( $config ) ?
			$config->get( 'AutoModeratorMultilingualConfigFalsePositivePageTitle' ) :
			$config->get( 'AutoModeratorFalsePositivePageTitle' );
	}

	/**
	 * @param Config $config
	 * @return mixed
	 */
	public static function getMaxRevertsEnabled( Config $config ): mixed {
		return self::isWikiMultilingual( $config ) ?
			$config->get( 'AutoModeratorMultilingualConfigEnableUserRevertsPerPage' ) :
			$config->get( 'AutoModeratorEnableUserRevertsPerPage' );
	}

	/**
	 * @param Config $config
	 * @return mixed
	 */
	public static function getMaxReverts( Config $config ): mixed {
		return self::isWikiMultilingual( $config ) ?
			$config->get( 'AutoModeratorMultilingualConfigUserRevertsPerPage' ) :
			$config->get( 'AutoModeratorUserRevertsPerPage' );
	}

	/**
	 * @param Config $config
	 * @return mixed
	 */
	public static function getSkipUserRights( Config $config ): mixed {
		return self::isWikiMultilingual( $config ) ?
			$config->get( 'AutoModeratorMultilingualConfigSkipUserRights' ) :
			$config->get( 'AutoModeratorSkipUserRights' );
	}

	/**
	 * @param Config $config
	 * @return mixed
	 */
	public static function getUseEditFlagMinor( Config $config ): mixed {
		return self::isWikiMultilingual( $config ) ?
			$config->get( 'AutoModeratorMultilingualConfigUseEditFlagMinor' ) :
			$config->get( 'AutoModeratorUseEditFlagMinor' );
	}

	/**
	 * @param Config $config
	 * @return mixed
	 */
	public static function getEnableBotFlag( Config $config ): mixed {
		return self::isWikiMultilingual( $config ) ?
			$config->get( 'AutoModeratorMultilingualConfigEnableBotFlag' ) :
			$config->get( 'AutoModeratorEnableBotFlag' );
	}

	/**
	 * @param Config $config
	 * @return mixed
	 */
	public static function getHelpPageLink( Config $config ): mixed {
		return self::isWikiMultilingual( $config ) ?
			$config->get( 'AutoModeratorMultilingualConfigHelpPageLink' ) :
			$config->get( 'AutoModeratorHelpPageLink' );
	}

	/**
	 * @param Config $config
	 * @return mixed
	 */
	public static function getRevertTalkPageMessageEnabled( Config $config ): mixed {
		return self::isWikiMultilingual( $config ) ?
			$config->get( 'AutoModeratorMultilingualConfigRevertTalkPageMessageEnabled' ) :
			$config->get( 'AutoModeratorRevertTalkPageMessageEnabled' );
	}

	/**
	 * @param Config $config
	 * @return mixed
	 */
	public static function getRevertTalkPageMessageRegisteredUsersOnly( Config $config ): mixed {
		return self::isWikiMultilingual( $config ) ?
			$config->get( 'AutoModeratorMultilingualConfigRevertTalkPageMessageRegisteredUsersOnly' ) :
			$config->get( 'AutoModeratorRevertTalkPageMessageRegisteredUsersOnly' );
	}

	/**
	 * @param Config $config
	 * @return mixed
	 */
	public static function getEnableRevisionCheck( Config $config ): mixed {
		return self::isWikiMultilingual( $config ) ?
			$config->get( 'AutoModeratorMultilingualConfigEnableRevisionCheck' ) :
			$config->get( 'AutoModeratorEnableRevisionCheck' );
	}

	/**
	 * @param Config $config
	 * @return mixed
	 */
	public static function getEnableLogOnlyMode( Config $config ): mixed {
		return self::isWikiMultilingual( $config ) ?
			$config->get( 'AutoModeratorMultilingualEnableLogOnlyMode' ) :
			$config->get( 'AutoModeratorEnableLogOnlyMode' );
	}

	/**
	 * @param HttpRequestFactory $httpRequestFactory
	 * @param Config $config
	 * @return LiftWingClient
	 */
	public static function initializeLiftWingClient(
		HttpRequestFactory $httpRequestFactory,
		Config $config
	): LiftWingClient {
		if ( self::isMultiLingualRevertRiskEnabled( $config ) ) {
			$model = 'revertrisk-multilingual';
			$hostHeaderKey = 'AutoModeratorLiftWingMultiLingualRevertRiskHostHeader';
		} else {
			$model = 'revertrisk-language-agnostic';
			$hostHeaderKey = 'AutoModeratorLiftWingRevertRiskHostHeader';
		}
		$hostHeader = $config->get( 'AutoModeratorLiftWingAddHostHeader' ) ? $config->get( $hostHeaderKey ) : null;
		$lang = self::getLanguageConfiguration( $config );
		return new LiftWingClient(
			$httpRequestFactory,
			$model,
			$lang,
			$config->get( 'AutoModeratorLiftWingBaseUrl' ),
			$hostHeader );
	}

	/**
	 * @return ApiClient
	 */
	public static function initializeApiClient(): ApiClient {
		return new ApiClient();
	}

	/**
	 * @param Config $config
	 * @return false|string
	 */
	public static function getLanguageConfiguration( Config $config ) {
		$wikiId = self::getWikiID( $config );
		return substr( $wikiId, 0, strpos( $wikiId, "wiki" ) );
	}

	/**
	 * @param mixed $cautionLevel
	 * @return float
	 */
	public static function getCautionLevel( mixed $cautionLevel ): float {
		$languageAgnosticThresholds = [
			'very-cautious' => 0.990,
			'cautious' => 0.985,
			'somewhat-cautious' => 0.980,
			'less-cautious' => 0.975
		];
		return $languageAgnosticThresholds[$cautionLevel];
	}
}
