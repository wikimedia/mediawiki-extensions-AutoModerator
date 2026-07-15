<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\AutoModerator;

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
	public function findComment( string $commentHeader, Title $userTalkPageTitle ): array {
		try {
			$context = new DerivativeContext( RequestContext::getMain() );
			$headerNoEqualsSymbol = trim( str_replace( '==', '', $commentHeader ) );
			$headerWithoutSpaces = str_replace( ' ', '_', $headerNoEqualsSymbol );
			$userTalkPageString = $userTalkPageTitle->getPrefixedText();
			$queryParams = [
				'action' => 'discussiontoolsfindcomment',
				'format' => 'json',
				'heading' => $headerWithoutSpaces,
				'page' => $userTalkPageString,
				'formatversion' => '2',
			];
			$data = $this->executeApiQuery( $context, $queryParams );
		} catch ( ApiUsageException ) {
			return [];
		}

		if ( array_key_exists( 'discussiontoolsfindcomment', $data ) ) {
			// From findcomment, we only need the comment id and couldredirect
			return $this->checkCommentRedirects(
				$data['discussiontoolsfindcomment'],
				$userTalkPageString
			);
		} else {
			return [];
		}
	}

	/**
	 * @param Title $userTalkPageTitle
	 *
	 * @return array Decoded response
	 */
	public function getUserTalkPageInfo( Title $userTalkPageTitle ): array {
		$context = new DerivativeContext( RequestContext::getMain() );
		$queryParams = [
			'action' => 'discussiontoolspageinfo',
			'format' => 'json',
			'page' => $userTalkPageTitle->getPrefixedText(),
			'prop' => 'threaditemshtml',
			'formatversion' => '2',
		];

		return $this->executeApiQuery( $context, $queryParams );
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
	public function addFollowUpComment(
		string $commentId,
		Title $userTalkPageTitle,
		string $followUpComment,
		User $autoModeratorUser
	): array {
		$requestContext = RequestContext::getMain();
		$requestContext->setUser( $autoModeratorUser );
		$context = new DerivativeContext( $requestContext );
		$context->setUser( $autoModeratorUser );
		$token = $autoModeratorUser->getEditTokenObject( '', $context->getRequest() )->toString();
		$queryParams = [
			'action' => 'discussiontoolsedit',
			'format' => 'json',
			'paction' => 'addcomment',
			'page' => $userTalkPageTitle->getPrefixedText(),
			'commentid' => $commentId,
			'wikitext' => $followUpComment,
			'token' => $token,
			'formatversion' => '2'
		];

		return $this->executeApiQuery( $context, $queryParams );
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
	public function addTopic(
		string $commentHeader,
		Title $userTalkPageTitle,
		string $talkPageMessage,
		string $editSummary,
		User $autoModeratorUser
	): array {
		$requestContext = RequestContext::getMain();
		$requestContext->setUser( $autoModeratorUser );
		$context = new DerivativeContext( $requestContext );
		$context->setUser( $autoModeratorUser );
		$token = $autoModeratorUser->getEditTokenObject( '', $context->getRequest() )->toString();
		$queryParams = [
			'action' => 'discussiontoolsedit',
			'format' => 'json',
			'paction' => 'addtopic',
			'page' => $userTalkPageTitle->getPrefixedText(),
			'wikitext' => $talkPageMessage,
			'sectiontitle' => $commentHeader,
			'summary' => $editSummary,
			'token' => $token,
			'formatversion' => '2'
		];

		return $this->executeApiQuery( $context, $queryParams );
	}

	private function executeApiQuery( DerivativeContext $context, array $queryParams ): array {
		$context->setRequest(
			new DerivativeRequest(
				$context->getRequest(),
				$queryParams
			)
		);
		$api = new ApiMain( $context, enableWrite: true );
		$api->execute();
		return $api->getResult()->getResultData();
	}

	/**
	 * Parses the discussiontoolsfindcomment API response array to check if there are any comments
	 * where we can append a follow-up message. If one exists, it returns true and the id
	 * @param array $comments
	 * @param string $userTalkPageString
	 * @return array
	 */
	private function checkCommentRedirects( array $comments, string $userTalkPageString ): array {
		$couldRedirect = false;
		$commentId = '';
		foreach ( $comments as $comment ) {
			if ( $comment['couldredirect'] && $comment['title'] === $userTalkPageString ) {
				$couldRedirect = true;
				$commentId = $comment['id'];
			}
		}
		return [ 'couldredirect' => $couldRedirect, 'id' => $commentId ];
	}
}
