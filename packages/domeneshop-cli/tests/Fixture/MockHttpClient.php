<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Cli\Tests\Fixture;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A canned PSR-18 client for tests. Records every request and replays a queue
 * of responses; once the queue is down to its last entry, that entry is
 * returned for any further calls.
 */
final class MockHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface> */
    private array $responses;

    public function __construct(ResponseInterface ...$responses)
    {
        $this->responses = array_values($responses);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $response = \count($this->responses) > 1 ? array_shift($this->responses) : $this->responses[0];
        self::record($request, $response);

        return $response;
    }

    /**
     * Records every response this mock serves, when DOMENESHOP_RESPONSE_COVERAGE
     * names a file to append to.
     *
     * It exists so `composer coverage:responses` can answer a question the test
     * names cannot: is every response the OpenAPI document promises actually
     * exercised somewhere? Off unless the variable is set, so an ordinary run
     * pays nothing.
     */
    private static function record(RequestInterface $request, ResponseInterface $response): void
    {
        $file = getenv('DOMENESHOP_RESPONSE_COVERAGE');
        if ($file === false || $file === '') {
            return;
        }

        $line = \sprintf(
            "%s\t%s\t%d\n",
            $request->getMethod(),
            (string) parse_url((string) $request->getUri(), PHP_URL_PATH),
            $response->getStatusCode(),
        );

        file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }


    public function lastRequest(): RequestInterface
    {
        return $this->requests[array_key_last($this->requests)];
    }

    public function lastUri(): string
    {
        return (string) $this->lastRequest()->getUri();
    }

    public function lastMethod(): string
    {
        return $this->lastRequest()->getMethod();
    }

    /** Path of the last request, with the /v0 base prefix stripped. */
    public function lastPath(): string
    {
        return (string) parse_url($this->lastUri(), PHP_URL_PATH);
    }

    /** @return array<string, string> Decoded query parameters of the last request. */
    public function lastQuery(): array
    {
        parse_str((string) parse_url($this->lastUri(), PHP_URL_QUERY), $query);

        /** @var array<string, string> $query */
        return $query;
    }

    /** @return array<string, mixed> The decoded JSON body of the last request. */
    public function lastBody(): array
    {
        $body = (string) $this->lastRequest()->getBody();

        /** @var array<string, mixed> $decoded */
        $decoded = $body === '' ? [] : (array) json_decode($body, true);

        return $decoded;
    }

    public function lastHeader(string $name): string
    {
        return $this->lastRequest()->getHeaderLine($name);
    }

    public function callCount(): int
    {
        return \count($this->requests);
    }
}
