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
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Status\Status;
use RuntimeException;

class LiftWingClient {

	/** @var string */
	private $model;

	/** @var string */
	private $lang;

	/** @var bool */
	private $passedPreCheck;

	public function __construct(
		string $model,
		string $lang,
		bool $passedPreCheck = false
	) {
		$this->model = $model;
		$this->lang = $lang;
		$this->passedPreCheck = $passedPreCheck;
	}

	/**
	 * @param string $model
	 * @param int $revId
	 * @return array
	 */
	private function createRevisionNotFoundResponse(
		string $model,
		int $revId
	) {
		$error_message = "RevisionNotFound: Could not find revision ({revision}:{$revId})";
		$error_type = "RevisionNotFound";
		return [
			$this->lang => [
				"models" => [
					$model => [
						// @todo: add model version
						"version" => null,
					],
				],
				"scores" => [
					$revId => [
						"error" => [
							"message" => $error_message,
							"type" => $error_type,
						],
					],
				],
			],
		];
	}

	/**
	 * Make a single call to LW revert risk model for one revid and return the decoded result.
	 *
	 * @param int $revId
	 *
	 * @return array Decoded response
	 */
	public function get( $revId ) {
		if ( !$this->passedPreCheck ) {
			return [];
		}
		$url = 'https://api.wikimedia.org/service/lw/inference/v1/models/' . $this->model . ':predict';
		$logger = LoggerFactory::getInstance( 'AutoModerator' );
		$logger->debug( "AutoModerator Requesting: {$url} " . __METHOD__ );
		$httpRequestFactory = MediaWikiServices::getInstance()->getHttpRequestFactory();
		$req = $httpRequestFactory->create( $url, [
				'method' => 'POST',
				'postData' => json_encode( [
					'rev_id' => (int)$revId,
					'lang' => $this->lang,
				] ),
			] );
		$status = $req->execute();
		if ( !$status->isOK() ) {
			$message = "Failed to make LiftWing request to [{$url}], " .
				Status::wrap( $status )->getMessage()->inLanguage( 'en' )->text();
			// Server time out, try again once
			if ( $req->getStatus() === 504 ) {
				$req = $httpRequestFactory->create( $url, [
					'method' => 'POST',
					'postData' => json_encode( [ 'rev_id' => (int)$revId ] ),
				] );
				$status = $req->execute();
				if ( !$status->isOK() ) {
					throw new RuntimeException( $message );
				}
			} elseif ( $req->getStatus() === 400 ) {
				$logger->debug( "400 Bad Request: {$message} " . __METHOD__ );
				$data = FormatJson::decode( $req->getContent(), true );
				if ( isset( $data['error'] ) &&
					strpos( $data["error"], "The MW API does not have any info related to the rev-id" ) === 0 ) {
					return $this->createRevisionNotFoundResponse( $this->model, $revId );
				} else {
					throw new RuntimeException( $message );
				}
			} else {
				throw new RuntimeException( $message );
			}
		}
		$json = $req->getContent();
		$logger->debug( "Raw response: {$json} " . __METHOD__ );
		$data = FormatJson::decode( $json, true );
		if ( !$data || !empty( $data['error'] ) ) {
			throw new RuntimeException( "Bad response from Lift Wing endpoint [{$url}]: {$json}" );
		}
		return $data;
	}
}
