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

use MediaWiki\Api\ApiMain;
use MediaWiki\Api\ApiUsageException;
use MediaWiki\Context\DerivativeContext;
use MediaWiki\Context\RequestContext;
use MediaWiki\Request\DerivativeRequest;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

class ApiClient {

	/**
	 * Make a single call to MediaWiki API discussiontoolsfindcomment and return the decoded result.
	 *
	 * @param string $commentHeader
	 * @param Title $userTalkPageTitle
	 *
	 * @return array Decoded response
	 */
	public function findComment( string $commentHeader, Title $userTalkPageTitle ) {
		try {
			$context = new DerivativeContext( RequestContext::getMain() );
			$headerNoEqualsSymbol = trim( str_replace( "==", "", $commentHeader ) );
			$headerWithoutSpaces = str_replace( " ", "_", $headerNoEqualsSymbol );
			$queryParams = [
				"action" => "discussiontoolsfindcomment",
				"format" => "json",
				"heading" => $headerWithoutSpaces,
				"page" => $userTalkPageTitle->getTalkNsText() . ':' . $userTalkPageTitle->getText(),
				"formatversion" => "2"
			];
			$data = $this->executeApiQuery( $context, $queryParams );
		} catch ( ApiUsageException $e ) {
			return [];
		}

		return $data;
	}

	/**
	 * @param Title $userTalkPageTitle
	 *
	 * @return array Decoded response
	 */
	public function getUserTalkPageInfo( Title $userTalkPageTitle ) {
		$context = new DerivativeContext( RequestContext::getMain() );
		$queryParams = [
			"action" => "discussiontoolspageinfo",
			"format" => "json",
			"page" => $userTalkPageTitle->getTalkNsText() . ':' . $userTalkPageTitle->getText(),
			"prop" => "threaditemshtml",
			"formatversion" => "2"
		];

		$data = $this->executeApiQuery( $context, $queryParams );

		return $data;
	}

	/**
	 * Make a single call to MediaWiki API discussiontoolsfindcomment and return the decoded result.
	 *
	 * @param string $commentId
	 * @param Title $userTalkPageTitle
	 * @param string $followUpComment
	 * @param User $autoModeratorUser
	 *
	 * @return array Decoded response
	 */
	public function addFollowUpComment( string $commentId, Title $userTalkPageTitle, string $followUpComment,
		User $autoModeratorUser ) {
		$requestContext = RequestContext::getMain();
		$requestContext->setUser( $autoModeratorUser );
		$context = new DerivativeContext( $requestContext );
		$context->setUser( $autoModeratorUser );
		$token = $autoModeratorUser->getEditTokenObject( '', $context->getRequest() )->toString();
		$queryParams = [
			"action" => "discussiontoolsedit",
			"format" => "json",
			"paction" => "addcomment",
			"page" => $userTalkPageTitle->getTalkNsText() . ':' . $userTalkPageTitle->getText(),
			"commentid" => $commentId,
			"wikitext" => $followUpComment,
			"token" => $token,
			"formatversion" => "2"
		];

		$data = $this->executeApiQuery( $context, $queryParams );

		return $data;
	}

	/**
	 * Make a single call to MediaWiki API discussiontoolsfindcomment and return the decoded result.
	 *
	 * @param string $commentHeader
	 * @param Title $userTalkPageTitle
	 * @param string $talkPageMessage
	 * @param string $editSummary
	 * @param User $autoModeratorUser
	 *
	 * @return array Decoded response
	 */
	public function addTopic( string $commentHeader, Title $userTalkPageTitle, string $talkPageMessage,
		string $editSummary, User $autoModeratorUser ) {
		$requestContext = RequestContext::getMain();
		$requestContext->setUser( $autoModeratorUser );
		$context = new DerivativeContext( $requestContext );
		$context->setUser( $autoModeratorUser );
		$token = $autoModeratorUser->getEditTokenObject( '', $context->getRequest() )->toString();
		$queryParams = [
			"action" => "discussiontoolsedit",
			"format" => "json",
			"paction" => "addtopic",
			"page" => $userTalkPageTitle->getTalkNsText() . ':' . $userTalkPageTitle->getText(),
			"wikitext" => $talkPageMessage,
			"sectiontitle" => $commentHeader,
			"summary" => $editSummary,
			"token" => $token,
			"formatversion" => "2"
		];

		$data = $this->executeApiQuery( $context, $queryParams );

		return $data;
	}

	/**
	 * @param DerivativeContext $context
	 * @param array $queryParams
	 *
	 * @return array $data
	 */
	private function executeApiQuery( DerivativeContext $context, array $queryParams ) {
		$context->setRequest(
			new DerivativeRequest(
				$context->getRequest(),
				$queryParams
			)
		);
		$api = new ApiMain(
			$context,
			true
		);
		$api->execute();
		$data = $api->getResult()->getResultData();
		return $data;
	}
}
