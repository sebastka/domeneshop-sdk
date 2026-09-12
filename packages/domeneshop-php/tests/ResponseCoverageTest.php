<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Tests;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Sebastka\Domeneshop\DomeneshopClient;
use Sebastka\Domeneshop\Exception\BadRequestException;
use Sebastka\Domeneshop\Exception\ConflictException;
use Sebastka\Domeneshop\Exception\ForbiddenException;
use Sebastka\Domeneshop\Exception\NotFoundException;
use Sebastka\Domeneshop\Exception\PreconditionFailedException;
use Sebastka\Domeneshop\Exception\UnauthorizedException;
use Sebastka\Domeneshop\Model\ARecord;
use Sebastka\Domeneshop\Model\HttpForward;

/**
 * Drives every operation against every response its OpenAPI document promises.
 *
 * The point is not to re-test what the per-resource suites already cover, but to
 * close a specific gap: happy paths were well covered while documented *error*
 * responses mostly were not, so a wrongly-mapped status could have gone
 * unnoticed on any endpoint but `/domains`.
 *
 * `composer coverage:responses` checks this mechanically — it records what the
 * suite actually exercises and compares it against the spec — so a response
 * added to the document without a test here fails the check.
 */
final class ResponseCoverageTest extends DomeneshopTestCase
{
    /** Status code -> the exception the client must raise for it. */
    private const EXPECTED_EXCEPTION = [
        400 => BadRequestException::class,
        401 => UnauthorizedException::class,
        403 => ForbiddenException::class,
        404 => NotFoundException::class,
        409 => ConflictException::class,
        412 => PreconditionFailedException::class,
    ];

    /**
     * One entry per operation: how to invoke it, and a success body the client
     * can decode.
     *
     * @return iterable<string, array{\Closure(DomeneshopClient): mixed, list<int>, mixed, int}>
     */
    public static function operationProvider(): iterable
    {
        yield 'GET /domains' => [
            static fn (DomeneshopClient $c): mixed => $c->domains()->list(),
            [401], [], 200,
        ];
        yield 'GET /domains/{domainId}' => [
            static fn (DomeneshopClient $c): mixed => $c->domains()->get(1),
            [401, 403, 404], ['id' => 1, 'domain' => 'example.com'], 200,
        ];
        yield 'GET /domains/{domainId}/dns' => [
            static fn (DomeneshopClient $c): mixed => $c->dns()->list(1),
            [401, 403, 404], [], 200,
        ];
        yield 'POST /domains/{domainId}/dns' => [
            static fn (DomeneshopClient $c): mixed => $c->dns()->create(1, new ARecord('www', '203.0.113.10')),
            [400, 401, 403, 404], ['id' => 1], 201,
        ];
        yield 'GET /domains/{domainId}/dns/{recordId}' => [
            static fn (DomeneshopClient $c): mixed => $c->dns()->get(1, 2),
            [401, 403, 404], ['id' => 2, 'host' => '@', 'type' => 'A', 'data' => '203.0.113.10'], 200,
        ];
        yield 'PUT /domains/{domainId}/dns/{recordId}' => [
            static fn (DomeneshopClient $c): mixed => $c->dns()->update(1, 2, new ARecord('www', '203.0.113.10')),
            [400, 401, 403, 404], null, 204,
        ];
        yield 'DELETE /domains/{domainId}/dns/{recordId}' => [
            static fn (DomeneshopClient $c): mixed => $c->dns()->delete(1, 2),
            [401, 403, 404], null, 204,
        ];
        yield 'GET /domains/{domainId}/forwards/' => [
            static fn (DomeneshopClient $c): mixed => $c->forwards()->list(1),
            [401, 403, 404], [], 200,
        ];
        yield 'POST /domains/{domainId}/forwards/' => [
            static fn (DomeneshopClient $c): mixed => $c->forwards()->create(1, new HttpForward('www', 'https://example.com')),
            [400, 401, 403, 404, 409], null, 201,
        ];
        yield 'PUT /domains/{domainId}/forwards/{host}' => [
            static fn (DomeneshopClient $c): mixed => $c->forwards()->update(1, 'www', new HttpForward('www', 'https://example.com')),
            [400, 401, 403, 404, 412], ['host' => 'www', 'url' => 'https://example.com', 'frame' => false], 200,
        ];
        yield 'DELETE /domains/{domainId}/forwards/{host}' => [
            static fn (DomeneshopClient $c): mixed => $c->forwards()->delete(1, 'www'),
            [401, 403, 404], null, 204,
        ];
        yield 'GET /invoices' => [
            static fn (DomeneshopClient $c): mixed => $c->invoices()->list(),
            [401], [], 200,
        ];
        yield 'GET /invoices/{invoiceId}' => [
            static fn (DomeneshopClient $c): mixed => $c->invoices()->get(1),
            [401, 404], ['id' => 1, 'amount' => 120], 200,
        ];
        yield 'GET /dyndns/update' => [
            static fn (DomeneshopClient $c): mixed => $c->dynDns()->update('home.example.com'),
            [401, 404], null, 204,
        ];

    }

    /**
     * @param \Closure(DomeneshopClient): mixed $invoke
     * @param list<int>                         $errorCodes
     */
    #[DataProvider('operationProvider')]
    public function testEveryDocumentedErrorResponseMapsToItsException(
        \Closure $invoke,
        array $errorCodes,
        mixed $successBody,
        int $successCode,
    ): void {
        foreach ($errorCodes as $code) {
            $client = $this->client(self::error($code, 'the server said no'));

            try {
                $invoke($client);
                self::fail(\sprintf('A %d response did not raise an exception.', $code));
            } catch (\Throwable $e) {
                self::assertInstanceOf(
                    self::EXPECTED_EXCEPTION[$code],
                    $e,
                    \sprintf('A %d should map to %s', $code, self::EXPECTED_EXCEPTION[$code]),
                );
                self::assertSame($code, $e->statusCode);
            }
        }
    }

    /**
     * @param \Closure(DomeneshopClient): mixed $invoke
     * @param list<int>                         $errorCodes
     */
    #[DataProvider('operationProvider')]
    public function testEveryDocumentedSuccessResponseIsAccepted(
        \Closure $invoke,
        array $errorCodes,
        mixed $successBody,
        int $successCode,
    ): void {
        $response = $successBody === null
            ? new Response($successCode, [], '')
            : self::json($successBody, $successCode);

        $client = $this->client($response);

        // No exception is the assertion: the client must accept the documented
        // success shape for every operation.
        $invoke($client);

        self::assertSame(1, $this->http->callCount());
    }

    /**
     * `GET /domains/{domainId}/forwards/{host}` is the one documented operation
     * the client never calls: that route is broken server-side (it answers 404
     * for every host), so `Forwards::get()` reads the collection and filters
     * instead. The route stays in the spec because the API really does expose
     * it — the spec describes the API, not our workarounds.
     */
    public function testGetForwardReadsTheCollectionInsteadOfTheBrokenItemRoute(): void
    {
        $client = $this->client(self::json([['host' => 'www', 'url' => 'https://example.com', 'frame' => false]]));

        self::assertSame('https://example.com', $client->forwards()->get(1, 'www')->url);
        self::assertSame('/v0/domains/1/forwards/', $this->http->lastPath());
    }
}
