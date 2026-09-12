<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Tests;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Sebastka\Domeneshop\DomeneshopClient;
use Sebastka\Domeneshop\Exception\BadRequestException;
use Sebastka\Domeneshop\Exception\ConflictException;
use Sebastka\Domeneshop\Exception\ForbiddenException;
use Sebastka\Domeneshop\Exception\NotFoundException;
use Sebastka\Domeneshop\Exception\PreconditionFailedException;
use Sebastka\Domeneshop\Exception\RateLimitException;
use Sebastka\Domeneshop\Exception\ServerException;
use Sebastka\Domeneshop\Exception\TransportException;
use Sebastka\Domeneshop\Exception\UnauthorizedException;
use Sebastka\Domeneshop\Tests\Fixture\ThrowingHttpClient;

final class TransportTest extends DomeneshopTestCase
{
    public function testRequestsCarryBasicAuthBuiltFromTokenAndSecret(): void
    {
        $client = $this->client(self::json([]));
        $client->domains()->list();

        self::assertSame(
            'Basic ' . base64_encode('TOKEN:SECRET'),
            $this->http->lastHeader('Authorization'),
        );
    }

    public function testRequestsIdentifyTheClient(): void
    {
        $client = $this->client(self::json([]));
        $client->domains()->list();

        self::assertStringContainsString('domeneshop-php', $this->http->lastHeader('User-Agent'));
        self::assertSame('application/json', $this->http->lastHeader('Accept'));
    }

    public function testBaseUriIsPrefixedToEveryPath(): void
    {
        $client = $this->client(self::json([]));
        $client->domains()->list();

        self::assertStringStartsWith(DomeneshopClient::DEFAULT_BASE_URI . '/domains', $this->http->lastUri());
    }

    /**
     * @return iterable<string, array{int, class-string<\Throwable>}>
     */
    public static function statusProvider(): iterable
    {
        yield '400' => [400, BadRequestException::class];
        yield '401' => [401, UnauthorizedException::class];
        yield '403' => [403, ForbiddenException::class];
        yield '404' => [404, NotFoundException::class];
        yield '409' => [409, ConflictException::class];
        yield '412' => [412, PreconditionFailedException::class];
        yield '429' => [429, RateLimitException::class];
        yield '500' => [500, ServerException::class];
        yield '503' => [503, ServerException::class];
    }

    /**
     * @param class-string<\Throwable> $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('statusProvider')]
    public function testStatusCodesMapToTypedExceptions(int $status, string $expected): void
    {
        $client = $this->client(self::error($status, 'something went wrong'));

        $this->expectException($expected);
        $client->domains()->list();
    }

    public function testApiExceptionCarriesTheServersExplanation(): void
    {
        $client = $this->client(self::error(400, 'ttl must be a multiple of 60', 'invalid_ttl'));

        try {
            $client->domains()->list();
            self::fail('Expected a BadRequestException.');
        } catch (BadRequestException $e) {
            self::assertSame(400, $e->statusCode);
            self::assertSame('ttl must be a multiple of 60', $e->help);
            self::assertSame('invalid_ttl', $e->errorCode);
            self::assertSame(400, $e->getCode());
            self::assertSame('GET /domains', $e->path);
            self::assertStringContainsString('ttl must be a multiple of 60', $e->getMessage());
        }
    }

    public function testCredentialsNeverLeakIntoTheExceptionMessage(): void
    {
        $client = $this->client(self::error(401, 'unauthorized'));

        try {
            $client->domains()->list();
            self::fail('Expected an UnauthorizedException.');
        } catch (UnauthorizedException $e) {
            self::assertStringNotContainsString('SECRET', $e->getMessage());
            self::assertStringNotContainsString('TOKEN', $e->getMessage());
        }
    }

    public function testAnErrorBodyWithoutAKnownKeyStillProducesAMessage(): void
    {
        $this->http = new Fixture\MockHttpClient(new Response(500, [], 'upstream exploded'));
        $factory = new HttpFactory();
        $client = new DomeneshopClient('TOKEN', 'SECRET', DomeneshopClient::DEFAULT_BASE_URI, $this->http, $factory, $factory);

        try {
            $client->domains()->list();
            self::fail('Expected a ServerException.');
        } catch (ServerException $e) {
            self::assertNull($e->help);
            self::assertSame('upstream exploded', $e->body);
            self::assertStringContainsString('Domeneshop API request failed', $e->getMessage());
        }
    }

    public function testAConnectionFailureBecomesATransportException(): void
    {
        $factory = new HttpFactory();
        $client = new DomeneshopClient(
            'TOKEN',
            'SECRET',
            DomeneshopClient::DEFAULT_BASE_URI,
            new ThrowingHttpClient(),
            $factory,
            $factory,
        );

        $this->expectException(TransportException::class);
        $client->domains()->list();
    }

    public function testAnUndecodableSuccessBodyBecomesATransportException(): void
    {
        $client = $this->client(new Response(200, [], '{not json'));

        $this->expectException(TransportException::class);
        $client->domains()->list();
    }

    public function testAn204EmptyBodyDecodesToNullNotAnError(): void
    {
        $client = $this->client(self::noContent());

        $client->dns()->delete(1, 2);

        self::assertSame(1, $this->http->callCount());
    }

    public function testTheClientRefusesEmptyCredentials(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DomeneshopClient('', 'secret');
    }

    public function testTheClientRefusesAnEmptySecret(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DomeneshopClient('token', '   ');
    }

    public function testResourceAccessorsAreMemoised(): void
    {
        $client = $this->client(self::json([]));

        self::assertSame($client->domains(), $client->domains());
        self::assertSame($client->dns(), $client->dns());
        self::assertSame($client->forwards(), $client->forwards());
        self::assertSame($client->invoices(), $client->invoices());
        self::assertSame($client->dynDns(), $client->dynDns());
    }
}
