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
			$userTalkPageString = str_replace( "_", " ", $userTalkPageTitle->getTalkNsText() ) .
				':' . $userTalkPageTitle->getText();
			$queryParams = [
				"action" => "discussiontoolsfindcomment",
				"format" => "json",
				"heading" => $headerWithoutSpaces,
				"page" => $userTalkPageString,
				"formatversion" => "2"
			];
			$data = $this->executeApiQuery( $context, $queryParams );
		} catch ( ApiUsageException ) {
			return [];
		}

		if ( array_key_exists( "discussiontoolsfindcomment", $data ) ) {
			// From findcomment, we only need the comment id and couldredirect
			return $this->checkCommentRedirects( $data[ "discussiontoolsfindcomment" ],
				$userTalkPageString );
		} else {
			return [];
		}
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

	/**
	 * Parses the discussiontoolsfindcomment API response array to check if there are any comments
	 * where we can append a follow-up message. If one exists, it returns true and the id
	 * @param array $comments
	 * @param string $userTalkPageString
	 * @return array
	 */
	private function checkCommentRedirects( array $comments, string $userTalkPageString ) {
		$couldRedirect = false;
		$commentId = "";
		foreach ( $comments as $comment ) {
			if ( $comment[ "couldredirect" ] && $comment[ "title" ] === $userTalkPageString ) {
				$couldRedirect = true;
				$commentId = $comment[ "id" ];
			}
		}
		return [ "couldredirect" => $couldRedirect, "id" => $commentId ];
	}
}
