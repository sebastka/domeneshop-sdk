<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Tests\Fixture;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** A PSR-18 client that always fails at the transport level. */
final class ThrowingHttpClient implements ClientInterface
{
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        throw new class ('connection refused') extends \RuntimeException implements ClientExceptionInterface {
        };
    }
}
