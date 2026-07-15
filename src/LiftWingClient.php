<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\AutoModerator;

use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Json\FormatJson;
use RuntimeException;

class LiftWingClient {

	private string $userAgent;

	public function __construct(
		private readonly HttpRequestFactory $httpRequestFactory,
		private readonly string $model,
		private readonly string $lang,
		private readonly string $baseUrl,
		private readonly ?string $hostHeader = null
	) {
		$this->userAgent = "mediawiki.ext.AutoModerator.$lang";
	}

	/**
	 * Make a single call to LW revert risk model for one revid and return the decoded result.
	 *
	 * @param int $revId
	 *
	 * @return array Decoded response
	 */
	public function get( int $revId ): array {
		$url = $this->baseUrl . $this->model . ':predict';
		$req = $this->httpRequestFactory->create( $url, [
			'method' => 'POST',
			'postData' => json_encode( [
				'rev_id' => $revId,
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
				throw new RuntimeException( "LiftWingClient [$url]: $httpStatus $errorMessage" );
			// Do one retry for non-4xx errors
			} else {
				$req = $this->httpRequestFactory->create( $url, [
					'method' => 'POST',
					'postData' => json_encode( [
						'rev_id' => $revId,
						'lang' => $this->lang,
					] ),
				], __METHOD__ );
				$response = $req->execute();
				if ( !$response->isOK() ) {
					throw new RuntimeException( "LiftWingClient [$url]: $httpStatus $errorMessage" );
				}
			}
		}
		$json = $req->getContent();
		$data = FormatJson::decode( $json, true );
		if ( !$data || !empty( $data['error'] ) ) {
			throw new RuntimeException( "LiftWingCient [$url]: $json" );
		}
		return $data;
	}

	public function getBaseUrl(): string {
		return $this->baseUrl;
	}

	public function getHostHeader(): ?string {
		return $this->hostHeader;
	}

	public function getUserAgent(): string {
		return $this->userAgent;
	}

}
