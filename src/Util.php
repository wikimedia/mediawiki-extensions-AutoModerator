<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\AutoModerator;

use MediaWiki\Config\Config;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\User\User;
use MediaWiki\User\UserGroupManager;
use MediaWiki\WikiMap\WikiMap;
use UnexpectedValueException;

class Util {

	/**
	 * @param Config $config
	 * @return string Wiki ID used by AutoModerator.
	 */
	public static function getWikiID( Config $config ): string {
		$autoModeratorWikiId = $config->get( 'AutoModeratorWikiId' );
		if ( $autoModeratorWikiId ) {
			return $autoModeratorWikiId;
		}
		return WikiMap::getCurrentWikiId();
	}

	/**
	 * @return string The name that ORES uses for the language-agnostic model.
	 */
	public static function getORESLanguageAgnosticModelName(): string {
		return 'revertrisklanguageagnostic';
	}

	/**
	 * @return string The name that ORES uses for the multilingual model.
	 */
	public static function getORESMultiLingualModelName(): string {
		// TODO: This hasn't been added to ORES; check if the name matches the model name when it is
		return 'revertriskmultilingual';
	}

	/**
	 * @param Config $config
	 * @param ?ExtensionRegistry $extensionRegistry For use in unit tests.
	 * @return bool Whether ORES is loaded and the configured revert risk model is enabled.
	 */
	public static function doesORESSupportRevertRiskModel(
		Config $config,
		?ExtensionRegistry $extensionRegistry = null,
	): bool {
		$extensionRegistry ??= ExtensionRegistry::getInstance();
		$logger = LoggerFactory::getInstance( 'AutoModerator' );
		if ( !$extensionRegistry->isLoaded( 'ORES' ) ) {
			$logger->debug( __METHOD__ . ': ORES is not loaded; cannot check if revert risk model is supported' );
			return false;
		}
		$oresModels = $config->get( 'OresModels' );
		$revertRiskModelName = self::getRevertRiskModel( $config );
		$supported = $oresModels[ $revertRiskModelName ][ 'enabled' ] ?? false;
		if ( $supported ) {
			$logger->debug( __METHOD__ . ': ORES is loaded and model {0} is enabled', [ $revertRiskModelName ] );
		}
		return $supported;
	}

	/**
	 * Returns the revert risk model the revision will be scored from.
	 */
	public static function getRevertRiskModel( Config $config ): string {
		// Check if a multilingual configuration exists and is enabled
		if ( self::isMultiLingualRevertRiskEnabled( $config ) ) {
			return self::getORESMultiLingualModelName();
		} else {
			return self::getORESLanguageAgnosticModelName();
		}
	}

	/**
	 * Get a user to perform moderation actions.
	 */
	public static function getAutoModeratorUser( Config $config, UserGroupManager $userGroupManager ): User {
		$username = $config->get( 'AutoModeratorUsername' );
		$autoModeratorUser = User::newSystemUser( $username, [ 'steal' => true ] );
		'@phan-var User $autoModeratorUser';
		if ( !$autoModeratorUser ) {
			throw new UnexpectedValueException(
				"$username is invalid. Please change it."
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
	 * @return float An AutoModeratorRevertProbability threshold will be chosen depending on the model.
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
	 * Returns the revert risk model the revision will be scored from.
	 */
	public static function getMultiLingualThreshold( Config $config ): float {
		if ( $config->get( 'AutoModeratorMultilingualConfigMultilingualThreshold' ) ) {
			return $config->get( 'AutoModeratorMultilingualConfigMultilingualThreshold' );
		}
		$cautionLevel = $config->get( 'AutoModeratorMultilingualConfigCautionLevel' );
		return self::getCautionLevel( $cautionLevel );
	}

	/**
	 * Checks if multilingual revert risk is enabled on the wiki.
	 * See: https://meta.wikimedia.org/wiki/Machine_learning_models/Production/Multilingual_revert_risk#Motivation
	 * for more information.
	 * @param Config $config
	 * @return bool
	 */
	public static function isWikiMultilingual( Config $config ): bool {
		return $config->has( 'AutoModeratorMultiLingualRevertRisk' ) &&
			$config->get( 'AutoModeratorMultiLingualRevertRisk' );
	}

	/**
	 * Checks if multilingual revert risk is enabled on the wiki.
	 * See: https://meta.wikimedia.org/wiki/Machine_learning_models/Production/Multilingual_revert_risk#Motivation
	 * for more information.
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

	public static function getFalsePositivePageTitleText( Config $config ): ?string {
		return self::isWikiMultilingual( $config ) ?
			$config->get( 'AutoModeratorMultilingualConfigFalsePositivePageTitle' ) :
			$config->get( 'AutoModeratorFalsePositivePageTitle' );
	}

	public static function getMaxRevertsEnabled( Config $config ): mixed {
		return self::isWikiMultilingual( $config ) ?
			$config->get( 'AutoModeratorMultilingualConfigEnableUserRevertsPerPage' ) :
			$config->get( 'AutoModeratorEnableUserRevertsPerPage' );
	}

	public static function getMaxReverts( Config $config ): mixed {
		return self::isWikiMultilingual( $config ) ?
			$config->get( 'AutoModeratorMultilingualConfigUserRevertsPerPage' ) :
			$config->get( 'AutoModeratorUserRevertsPerPage' );
	}

	public static function getSkipUserRights( Config $config ): mixed {
		return self::isWikiMultilingual( $config ) ?
			$config->get( 'AutoModeratorMultilingualConfigSkipUserRights' ) :
			$config->get( 'AutoModeratorSkipUserRights' );
	}

	public static function getUseEditFlagMinor( Config $config ): mixed {
		return self::isWikiMultilingual( $config ) ?
			$config->get( 'AutoModeratorMultilingualConfigUseEditFlagMinor' ) :
			$config->get( 'AutoModeratorUseEditFlagMinor' );
	}

	public static function getEnableBotFlag( Config $config ): mixed {
		return self::isWikiMultilingual( $config ) ?
			$config->get( 'AutoModeratorMultilingualConfigEnableBotFlag' ) :
			$config->get( 'AutoModeratorEnableBotFlag' );
	}

	public static function getHelpPageLink( Config $config ): mixed {
		return self::isWikiMultilingual( $config ) ?
			$config->get( 'AutoModeratorMultilingualConfigHelpPageLink' ) :
			$config->get( 'AutoModeratorHelpPageLink' );
	}

	public static function getRevertTalkPageMessageEnabled( Config $config ): mixed {
		return self::isWikiMultilingual( $config ) ?
			$config->get( 'AutoModeratorMultilingualConfigRevertTalkPageMessageEnabled' ) :
			$config->get( 'AutoModeratorRevertTalkPageMessageEnabled' );
	}

	public static function getRevertTalkPageMessageRegisteredUsersOnly( Config $config ): mixed {
		return self::isWikiMultilingual( $config ) ?
			$config->get( 'AutoModeratorMultilingualConfigRevertTalkPageMessageRegisteredUsersOnly' ) :
			$config->get( 'AutoModeratorRevertTalkPageMessageRegisteredUsersOnly' );
	}

	public static function getEnableRevisionCheck( Config $config ): mixed {
		return self::isWikiMultilingual( $config ) ?
			$config->get( 'AutoModeratorMultilingualConfigEnableRevisionCheck' ) :
			$config->get( 'AutoModeratorEnableRevisionCheck' );
	}

	public static function getEnableLogOnlyMode( Config $config ): mixed {
		return self::isWikiMultilingual( $config ) ?
			$config->get( 'AutoModeratorMultilingualEnableLogOnlyMode' ) :
			$config->get( 'AutoModeratorEnableLogOnlyMode' );
	}

	public static function initializeLiftWingClient(
		HttpRequestFactory $httpRequestFactory,
		Config $config,
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
			$hostHeader,
		);
	}

	public static function initializeApiClient(): ApiClient {
		return new ApiClient();
	}

	public static function getLanguageConfiguration( Config $config ): false|string {
		$wikiId = self::getWikiID( $config );
		return substr( $wikiId, 0, strpos( $wikiId, 'wiki' ) );
	}

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
