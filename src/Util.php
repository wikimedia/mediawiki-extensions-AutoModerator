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

use FormatJson;
use MediaWiki\Config\Config;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\User;
use MediaWiki\User\UserGroupManager;
use MediaWiki\Utils\UrlUtils;
use MediaWiki\WikiMap\WikiMap;
use RequestContext;
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
	 * If the AutoModeratorRevertProbability configuration
	 * field is set below 0.95 we default to 0.95 to prevent
	 * large numbers of false positives.
	 * @param Config $config
	 * @return float AutoModeratorRevertProbability threshold
	 */
	public static function getRevertThreshold( Config $config ): float {
		$minimumThreshold = 0.95;
		$revertThreshold = $config->get( 'AutoModeratorRevertProbability' );
		if ( $revertThreshold < $minimumThreshold ) {
			return $minimumThreshold;
		}
		return $revertThreshold;
	}

	/**
	 * @param Config $config
	 * @return LiftWingClient
	 */
	public static function initializeLiftWingClient( Config $config ): LiftWingClient {
		$model = 'revertrisk-language-agnostic';
		$lang = self::getLanguageConfiguration( $config );
		$hostHeader = $config->get( 'AutoModeratorLiftWingAddHostHeader' ) ?
			$config->get( 'AutoModeratorLiftWingRevertRiskHostHeader' ) : null;
		return new LiftWingClient(
			$model,
			$lang,
			$config->get( 'AutoModeratorLiftWingBaseUrl' ),
			$hostHeader );
	}

	/**
	 * @param Config $config
	 * @return false|string
	 */
	public static function getLanguageConfiguration( Config $config ) {
		$wikiId = self::getWikiID( $config );
		return substr( $wikiId, 0, strpos( $wikiId, "wiki" ) );
	}
}
