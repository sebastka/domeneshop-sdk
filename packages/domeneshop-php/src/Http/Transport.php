<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Http;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Sebastka\Domeneshop\Exception\ApiException;
use Sebastka\Domeneshop\Exception\TransportException;

/**
 * Internal HTTP layer. Domeneshop authenticates with HTTP Basic — the API token
 * is the username and the secret is the password — so this class is the single
 * place that knows how to build an authenticated request, and the single place
 * responsible for keeping those credentials out of exceptions and logs.
 *
 * @internal Resource classes use this; it is not part of the public API.
 */
final class Transport
{
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly string $token,
        private readonly string $secret,
        private readonly string $baseUri,
        private readonly string $userAgent,
    ) {
    }

    /**
     * GET a path and decode the JSON body.
     *
     * @param array<string, scalar|null> $query Null values are dropped.
     *
     * @return mixed Decoded JSON, or null for an empty body.
     */
    public function get(string $path, array $query = []): mixed
    {
        return $this->send('GET', $path, $query);
    }

    /**
     * POST a JSON payload.
     *
     * @param array<string, mixed> $body
     *
     * @return mixed Decoded JSON, or null when the API answers 201/204 with no body.
     */
    public function post(string $path, array $body = []): mixed
    {
        return $this->send('POST', $path, [], $body);
    }

    /**
     * PUT a JSON payload.
     *
     * @param array<string, mixed> $body
     *
     * @return mixed Decoded JSON, or null when the API answers 204.
     */
    public function put(string $path, array $body = []): mixed
    {
        return $this->send('PUT', $path, [], $body);
    }

    /** DELETE a path. The API answers 204 with no body. */
    public function delete(string $path): void
    {
        $this->send('DELETE', $path);
    }

    /**
     * @param array<string, scalar|null> $query
     * @param array<string, mixed>|null  $body
     */
    private function send(string $method, string $path, array $query = [], ?array $body = null): mixed
    {
        $request = $this->requestFactory
            ->createRequest($method, $this->buildUrl($path, $query))
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', $this->userAgent)
            // Basic auth rather than PSR-18 client config, so the credentials
            // travel with the request no matter whose client is injected.
            ->withHeader('Authorization', 'Basic ' . base64_encode($this->token . ':' . $this->secret));

        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream(
                    (string) json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                ));
        }

        $label = $method . ' ' . $path;

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new TransportException(
                \sprintf('HTTP transport error while requesting %s', $label),
                previous: $e,
            );
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $this->fail($response, $label);
        }

        return $this->decode((string) $response->getBody(), $label);
    }

    /**
     * @param array<string, scalar|null> $query
     */
    private function buildUrl(string $path, array $query): string
    {
        $url = rtrim($this->baseUri, '/') . '/' . ltrim($path, '/');

        // Booleans would otherwise serialise as "1"/"" — the API wants true/false.
        $params = array_filter($query, static fn (mixed $v): bool => $v !== null);
        $params = array_map(
            static fn (mixed $v): string => \is_bool($v) ? ($v ? 'true' : 'false') : (string) $v,
            $params,
        );

        return $params !== [] ? $url . '?' . http_build_query($params) : $url;
    }

    /** @return never */
    private function fail(ResponseInterface $response, string $label): void
    {
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);

        $help = null;
        $errorCode = null;
        if (\is_array($decoded)) {
            // The API is inconsistent about which key carries the explanation,
            // so accept any of the shapes it is known to return.
            foreach (['help', 'error', 'message', 'detail'] as $key) {
                if (isset($decoded[$key]) && \is_string($decoded[$key]) && $decoded[$key] !== '') {
                    $help = $decoded[$key];
                    break;
                }
            }
            if (isset($decoded['code']) && \is_scalar($decoded['code'])) {
                $errorCode = (string) $decoded['code'];
            }
        }

        throw ApiException::fromResponse($response->getStatusCode(), $help, $label, $body, $errorCode);
    }

    private function decode(string $body, string $label): mixed
    {
        if (trim($body) === '') {
            return null;
        }

        try {
            return json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new TransportException(
                \sprintf('Failed to decode JSON response from %s', $label),
                previous: $e,
            );
        }
    }
}
