<?php

declare(strict_types=1);

namespace OCA\Astrolabe\Http;

use GuzzleHttp\Psr7\Response;
use OCP\Http\Client\IClient;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 adapter over Nextcloud's IClient.
 *
 * Lets services depend on the standard ``Psr\Http\Client\ClientInterface`` — so
 * they can be unit- and contract-tested against any PSR-18 client (e.g. the Pact
 * mock server) — while production traffic still flows through Nextcloud's
 * ``IClientService``, preserving its certificate bundle, proxy, and DNS settings.
 *
 * PSR-18 requires returning a response for every HTTP status (only transport
 * failures throw), so IClient is always invoked with ``http_errors => false``.
 */
class NextcloudPsr18Client implements ClientInterface {
	public function __construct(
		private IClient $client,
	) {
	}

	public function sendRequest(RequestInterface $request): ResponseInterface {
		$headers = [];
		foreach (array_keys($request->getHeaders()) as $name) {
			$name = (string)$name;
			$headers[$name] = $request->getHeaderLine($name);
		}

		$options = [
			'headers' => $headers,
			'http_errors' => false,
		];

		$body = (string)$request->getBody();
		if ($body !== '') {
			$options['body'] = $body;
		}

		$response = $this->client->request(
			$request->getMethod(),
			(string)$request->getUri(),
			$options,
		);

		// IResponse::getHeaders() returns flat header strings (array<string,
		// string>), unlike PSR-7's array<string, list<string>>; the Guzzle
		// Response constructor accepts either shape.
		/** @var array<string, string> $responseHeaders */
		$responseHeaders = $response->getHeaders();

		return new Response(
			$response->getStatusCode(),
			$responseHeaders,
			(string)$response->getBody(),
		);
	}
}
