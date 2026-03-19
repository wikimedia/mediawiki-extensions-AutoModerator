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

use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Json\FormatJson;
use RuntimeException;

class LiftWingClient {

	/** @var HttpRequestFactory */
	private $httpRequestFactory;

	/** @var string */
	private $model;

	/** @var string */
	private $lang;

	/** @var string */
	private string $baseUrl;

	/** @var ?string */
	private ?string $hostHeader;

	/** @var string */
	private string $userAgent;

	public function __construct(
		HttpRequestFactory $httpRequestFactory,
		string $model,
		string $lang,
		string $baseUrl,
		?string $hostHeader = null
	) {
		$this->httpRequestFactory = $httpRequestFactory;
		$this->model = $model;
		$this->lang = $lang;
		$this->baseUrl = $baseUrl;
		$this->hostHeader = $hostHeader;
		$this->userAgent = 'mediawiki.ext.AutoModerator.' . $this->lang;
	}

	/**
	 * Make a single call to LW revert risk model for one revid and return the decoded result.
	 *
	 * @param int $revId
	 *
	 * @return array Decoded response
	 */
	public function get( $revId ) {
		$url = $this->baseUrl . $this->model . ':predict';
		$req = $this->httpRequestFactory->create( $url, [
			'method' => 'POST',
			'postData' => json_encode( [
				'rev_id' => (int)$revId,
				'lang' => $this->lang,
			] ),
		], __METHOD__ );
		if ( $this->hostHeader ) {
			$req->setHeader( 'Host', $this->hostHeader );
		}
		$req->setHeader( 'User-Agent', $this->userAgent );
		$response = $req->execute();
		if ( !$response->isOK() ) {
			$httpStatus = $req->getStatus();
			$data = FormatJson::decode( $req->getContent(), true );

			if ( !$data ) {
				$data = [];
				$data['error'] = "$url returned $httpStatus for rev $revId {$this->lang}";
			}

			$errorMessage = $data['error'] ?? $data['detail'] ?? $data['httpReason'] ?? '';
			// Throw for 4xx errors
			if ( ( $httpStatus >= 400 ) && ( $httpStatus <= 499 ) ) {
				throw new RuntimeException( "LiftWingClient [{$url}]: {$httpStatus} {$errorMessage}" );
			// Do one retry for non-4xx errors
			} else {
				$req = $this->httpRequestFactory->create( $url, [
					'method' => 'POST',
					'postData' => json_encode( [
						'rev_id' => (int)$revId,
						'lang' => $this->lang,
					] ),
				], __METHOD__ );
				$response = $req->execute();
				if ( !$response->isOK() ) {
					throw new RuntimeException( "LiftWingClient [{$url}]: {$httpStatus} {$errorMessage}" );
				}
			}
		}
		$json = $req->getContent();
		$data = FormatJson::decode( $json, true );
		if ( !$data || !empty( $data['error'] ) ) {
			throw new RuntimeException( "LiftWingCient [{$url}]: {$json}" );
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
	 * @return string
	 */
	public function getUserAgent(): string {
		return $this->userAgent;
	}

}
