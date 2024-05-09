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
use MediaWiki\MediaWikiServices;
use RuntimeException;

class LiftWingClient {

	/** @var string */
	private $model;

	/** @var string */
	private $lang;

	/** @var bool */
	private $passedPreCheck;

	/** @var string */
	private string $baseUrl;

	/** @var ?string */
	private ?string $hostHeader;

	public function __construct(
		string $model,
		string $lang,
		string $baseUrl,
		bool $passedPreCheck = false,
		string $hostHeader = null
	) {
		$this->model = $model;
		$this->lang = $lang;
		$this->passedPreCheck = $passedPreCheck;
		$this->baseUrl = $baseUrl;
		$this->hostHeader = $hostHeader;
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
		$url = $this->baseUrl . $this->model . ':predict';
		$httpRequestFactory = MediaWikiServices::getInstance()->getHttpRequestFactory();
		$req = $httpRequestFactory->create( $url, [
			'method' => 'POST',
			'postData' => json_encode( [
				'rev_id' => (int)$revId,
				'lang' => $this->lang,
			] ),
		] );
		if ( $this->hostHeader ) {
			$req->setHeader( 'Host', $this->hostHeader );
		}
		$response = $req->execute();
		if ( !$response->isOK() ) {
			$httpStatus = $req->getStatus();
			$data = FormatJson::decode( $req->getContent(), true );
			if ( !$data ) {
				$data = [];
				$message = 'url returned status for rev rev_id lang';
				$data['error'] = strtr( $message, [
					'url' => $url,
					'status' => (string)$httpStatus,
					'rev_id' => (string)$revId,
					'lang' => $this->lang,
				] );
			}
			$errorMessage = $data['error'] ?? $data['detail'];
			if ( ( $httpStatus >= 400 ) && ( $httpStatus <= 499 ) ) {
				return $this->createErrorResponse( $httpStatus, $errorMessage, false );
			} else {
				$req = $httpRequestFactory->create( $url, [
					'method' => 'POST',
					'postData' => json_encode( [
						'rev_id' => (int)$revId,
						'lang' => $this->lang,
					] ),
				] );
				$response = $req->execute();
				if ( !$response->isOK() ) {
					return $this->createErrorResponse( $httpStatus, $errorMessage, true );
				}
			}
		}
		$json = $req->getContent();
		$data = FormatJson::decode( $json, true );
		if ( !$data || !empty( $data['error'] ) ) {
			throw new RuntimeException( "Bad response from Lift Wing endpoint [{$url}]: {$json}" );
		}
		return $data;
	}

	/**
	 * @return string
	 */
	public function getBaseUrl(): string {
		return $this->baseUrl;
	}

	/**
	 * @return ?string
	 */
	public function getHostHeader(): ?string {
		return $this->hostHeader;
	}

	/**
	 * @param int $httpStatus
	 * @param string $errorMessage
	 * @return array
	 */
	public function createErrorResponse(
		int $httpStatus,
		string $errorMessage,
		bool $allowRetries
	): array {
		return [
			"httpStatus" => $httpStatus,
			"errorMessage" => $errorMessage,
			"allowRetries" => $allowRetries
		];
	}

}
