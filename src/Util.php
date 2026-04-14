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
use MediaWiki\Context\RequestContext;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Json\FormatJson;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\User;
use MediaWiki\User\UserGroupManager;
use MediaWiki\Utils\UrlUtils;
use MediaWiki\WikiMap\WikiMap;
use RuntimeException;
use StatusValue;
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
		$languageAgnosticModel = self::getORESLanguageAgnosticModelName();
		$multiLingualModel = self::getORESMultiLingualModelName();

		$isLanguageModelEnabledConfig = self::isMultiLingualRevertRiskEnabled( $config );

		// Check if a multilingual configuration exists and is enabled
		if ( $isLanguageModelEnabledConfig ) {
			return $multiLingualModel;
		} else {
			return $languageAgnosticModel;
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
	 * Fetch JSON data from a remote URL, parse it and return the results.
	 * @param HttpRequestFactory $requestFactory
	 * @param string $url
	 * @param bool $isSameFarm Is the URL on the same wiki farm we are making the request from?
	 * @return StatusValue A status object with the parsed JSON value, or any errors.
	 *   (Warnings coming from the HTTP library will be logged and not included here.)
	 */
	public static function getJsonUrl(
		HttpRequestFactory $requestFactory, $url, $isSameFarm = false
	): StatusValue {
		$options = [
			'method' => 'GET',
			'userAgent' => $requestFactory->getUserAgent() . ' AutoModerator',
		];
		if ( $isSameFarm ) {
			$options['originalRequest'] = RequestContext::getMain()->getRequest();
		}
		$request = $requestFactory->create( $url, $options, __METHOD__ );
		$status = $request->execute();
		if ( $status->isOK() ) {
			$status->merge( FormatJson::parse( $request->getContent(), FormatJson::FORCE_ASSOC ), true );
		}
		// Log warnings here. The caller is expected to handle errors so do not double-log them.
		[ $errorStatus, $warningStatus ] = $status->splitByErrorType();
		if ( !$warningStatus->isGood() ) {
			// @todo replace 'en' with correct language configuration
			LoggerFactory::getInstance( 'AutoModerator' )->warning(
				$warningStatus->getWikiText( false, false, 'en' ),
				[ 'exception' => new RuntimeException ]
			);
		}
		return $errorStatus;
	}

	/**
	 * Get the action=raw URL for a (probably remote) title.
	 * Normal title methods would return nice URLs, which are usually disallowed for action=raw.
	 * We assume both wikis use the same URL structure.
	 * @param LinkTarget $title
	 * @param TitleFactory $titleFactory
	 * @return string
	 */
	public static function getRawUrl(
		LinkTarget $title,
		TitleFactory $titleFactory,
		UrlUtils $urlUtils
	): string {
		// Use getFullURL to get the interwiki domain.
		$url = $titleFactory->newFromLinkTarget( $title )->getFullURL();
		$parts = $urlUtils->parse( (string)$urlUtils->expand( $url, PROTO_CANONICAL ) );
		if ( !$parts ) {
			throw new UnexpectedValueException( 'URL is expected to be valid' );
		}
		$baseUrl = $parts['scheme'] . $parts['delimiter'] . $parts['host'];
		if ( isset( $parts['port'] ) && $parts['port'] ) {
			$baseUrl .= ':' . $parts['port'];
		}

		$localPageTitle = $titleFactory->makeTitle( $title->getNamespace(), $title->getDBkey() );
		return $baseUrl . $localPageTitle->getLocalURL( [ 'action' => 'raw' ] );
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
	 * @param Config $config
	 * @return LiftWingClient
	 */
	public static function initializeLiftWingClient( Config $config ): LiftWingClient {
		$isMultiLingualModelEnabled = self::isMultiLingualRevertRiskEnabled( $config );
		if ( $isMultiLingualModelEnabled ) {
			$model = 'revertrisk-multilingual';
			$hostHeaderKey = 'AutoModeratorLiftWingMultiLingualRevertRiskHostHeader';
		} else {
			$model = 'revertrisk-language-agnostic';
			$hostHeaderKey = 'AutoModeratorLiftWingRevertRiskHostHeader';
		}
		$hostHeader = $config->get( 'AutoModeratorLiftWingAddHostHeader' ) ? $config->get( $hostHeaderKey ) : null;
		$lang = self::getLanguageConfiguration( $config );
		return new LiftWingClient(
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
