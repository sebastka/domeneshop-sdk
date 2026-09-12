<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Tests;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Sebastka\Domeneshop\DomeneshopClient;
use Sebastka\Domeneshop\Tests\Fixture\MockHttpClient;

abstract class DomeneshopTestCase extends TestCase
{
    protected MockHttpClient $http;

    /** Build a client backed by a recording mock that replays the given responses. */
    protected function client(Response ...$responses): DomeneshopClient
    {
        if ($responses === []) {
            $responses = [new Response(204, [], '')];
        }
        $this->http = new MockHttpClient(...$responses);
        $factory = new HttpFactory();

        return new DomeneshopClient(
            'TOKEN',
            'SECRET',
            DomeneshopClient::DEFAULT_BASE_URI,
            $this->http,
            $factory,
            $factory,
        );
    }

    protected static function json(mixed $value, int $status = 200): Response
    {
        return new Response($status, [], (string) json_encode($value));
    }

    /** An error response in the shape the API returns. */
    protected static function error(int $status, string $help, ?string $code = null): Response
    {
        $body = ['help' => $help];
        if ($code !== null) {
            $body['code'] = $code;
        }

        return new Response($status, [], (string) json_encode($body));
    }

    /** A successful response with an empty body, as the 201/204 endpoints return. */
    protected static function noContent(int $status = 204): Response
    {
        return new Response($status, [], '');
    }
}
