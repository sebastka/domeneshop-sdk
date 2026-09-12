<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Tests\Contract;

use PHPUnit\Framework\Attributes\Group;
use Sebastka\Domeneshop\DomeneshopClient;
use Sebastka\Domeneshop\Exception\BadRequestException;
use Sebastka\Domeneshop\Exception\ForbiddenException;
use Sebastka\Domeneshop\Exception\NotFoundException;
use Sebastka\Domeneshop\Exception\UnauthorizedException;
use Sebastka\Domeneshop\Model\ARecord;
use Sebastka\Domeneshop\Model\RecordType;

/**
 * Drives every documented response against the **live** API.
 *
 * The offline suite proves the client maps a given status to the right
 * exception. This proves the API actually produces that status — a different
 * question, and the one that caught the `403`/`404` discrepancy below.
 *
 * Every write is a DNS record on a throwaway `sdk-test-` host, removed in a
 * `finally`. No forward is created: a forward cannot be deleted through the API
 * (see {@see \Sebastka\Domeneshop\Resource\Forwards}), so the cases that would
 * need one are written out but commented out, ready for when that is fixed.
 */
#[Group('contract')]
final class LiveResponseMatrixTest extends ContractTestCase
{
    /** A domain id that is certainly not in the account. */
    private const FOREIGN_DOMAIN = 999_999_999;

    /** A record id that is certainly not on our domain. */
    private const MISSING_RECORD = 999_999_999;

    /** A client whose credentials are deliberately wrong. */
    private function unauthorised(): DomeneshopClient
    {
        return new DomeneshopClient('invalid-token-for-contract-test', 'invalid-secret-for-contract-test');
    }

    // --- domains -----------------------------------------------------------

    public function testDomainsList(): void
    {
        self::assertIsArray($this->client()->domains()->list());                 // 200
        $this->expectException(UnauthorizedException::class);                    // 401
        $this->unauthorised()->domains()->list();
    }

    /**
     * The published document says `404` for a domain you cannot see. The API
     * answers **403** — for any id you do not own, including ids that cannot
     * exist. It never distinguishes "no such domain" from "not yours".
     */
    public function testDomainGet(): void
    {
        $domain = $this->writableDomain();

        self::assertSame($domain->id, $this->client()->domains()->get($domain->id)->id);   // 200

        try {
            $this->client()->domains()->get(self::FOREIGN_DOMAIN);
            self::fail('Expected a ForbiddenException for a domain outside the account.');
        } catch (ForbiddenException $e) {                                        // 403, documented as 404
            self::assertSame(403, $e->statusCode);
        }

        $this->expectException(UnauthorizedException::class);                    // 401
        $this->unauthorised()->domains()->get($domain->id);
    }

    // --- dns ---------------------------------------------------------------

    public function testDnsListAndGet(): void
    {
        $domain = $this->writableDomain();
        $client = $this->client();

        self::assertIsArray($client->dns()->list($domain->id));                  // 200

        try {
            $client->dns()->list(self::FOREIGN_DOMAIN);
            self::fail('Expected 403.');
        } catch (ForbiddenException) {                                           // 403
        }

        try {
            $client->dns()->get($domain->id, self::MISSING_RECORD);
            self::fail('Expected 404.');
        } catch (NotFoundException) {                                            // 404
        }

        $this->expectException(UnauthorizedException::class);                    // 401
        $this->unauthorised()->dns()->list($domain->id);
    }

    public function testDnsCreateUpdateDelete(): void
    {
        $domain = $this->writableDomain();
        $client = $this->client();
        $host = self::testHost();

        $recordId = $client->dns()->create($domain->id, new ARecord($host, '203.0.113.10'));  // 201

        try {
            self::assertGreaterThan(0, $recordId);

            // 400: the API rejects a malformed address rather than storing it.
            try {
                $client->dns()->create($domain->id, new ARecord($host, 'not-an-ip-address'));
                self::fail('Expected 400 for a malformed A record.');
            } catch (BadRequestException) {
            }

            // 403: a domain outside the account.
            try {
                $client->dns()->create(self::FOREIGN_DOMAIN, new ARecord($host, '203.0.113.10'));
                self::fail('Expected 403.');
            } catch (ForbiddenException) {
            }

            $client->dns()->update($domain->id, $recordId, new ARecord($host, '203.0.113.99'));  // 204

            try {
                $client->dns()->update($domain->id, $recordId, new ARecord($host, 'still-not-an-ip'));
                self::fail('Expected 400.');
            } catch (BadRequestException) {
            }

            try {
                $client->dns()->update($domain->id, self::MISSING_RECORD, new ARecord($host, '203.0.113.99'));
                self::fail('Expected 404.');
            } catch (NotFoundException) {
            }
        } finally {
            $client->dns()->delete($domain->id, $recordId);                      // 204
        }

        try {
            $client->dns()->delete($domain->id, $recordId);
            self::fail('Expected 404 deleting an already-deleted record.');
        } catch (NotFoundException) {                                            // 404
        }

        $this->expectException(UnauthorizedException::class);                    // 401
        $this->unauthorised()->dns()->delete($domain->id, self::MISSING_RECORD);
    }

    // --- forwards ----------------------------------------------------------

    /**
     * Only the cases that create nothing. Everything that needs a forward to
     * exist is below, commented out.
     */
    public function testForwardsReadAndRejectedWrites(): void
    {
        $domain = $this->writableDomain();
        $client = $this->client();

        self::assertIsArray($client->forwards()->list($domain->id));             // 200

        try {
            $client->forwards()->list(self::FOREIGN_DOMAIN);
            self::fail('Expected 403.');
        } catch (ForbiddenException) {                                           // 403
        }

        // 400: rejected before anything is created, so this is safe to run.
        try {
            $client->forwards()->create(
                $domain->id,
                new \Sebastka\Domeneshop\Model\HttpForward(self::testHost(), 'not-a-url'),
            );
            self::fail('Expected 400 for a URL with no scheme.');
        } catch (BadRequestException) {
        }

        // 404: the per-host route answers 404 for every host — see Forwards.
        try {
            $client->forwards()->delete($domain->id, 'sdk-test-does-not-exist');
            self::fail('Expected 404.');
        } catch (NotFoundException) {                                            // 404
        }

        $this->expectException(UnauthorizedException::class);                    // 401
        $this->unauthorised()->forwards()->list($domain->id);
    }

    /*
     * The remaining forward responses need a forward to exist, and a forward
     * created through the API cannot be removed through it — the per-host
     * endpoint answers 404 for every host, so `update()` and `delete()` have no
     * working route. Running these would strand a forward on a real domain, to
     * be cleared by hand in the web interface.
     *
     * They are kept here, complete, so that re-enabling them is uncommenting a
     * block once Domeneshop fixes the endpoint. Covers:
     *   POST   /domains/{domainId}/forwards/        201, 409
     *   GET    /domains/{domainId}/forwards/{host}  200
     *   PUT    /domains/{domainId}/forwards/{host}  200, 412
     *   DELETE /domains/{domainId}/forwards/{host}  204
     *
    public function testForwardsWriteLifecycle(): void
    {
        $domain = $this->writableDomain();
        $client = $this->client();
        $host = self::testHost();

        $client->forwards()->create($domain->id, new HttpForward($host, 'https://example.com'));   // 201

        try {
            $fetched = $client->forwards()->get($domain->id, $host);                                // 200
            self::assertSame('https://example.com', $fetched->url);

            // 409: a second forward on the same host collides.
            try {
                $client->forwards()->create($domain->id, new HttpForward($host, 'https://other.example.com'));
                self::fail('Expected 409 for a colliding forward.');
            } catch (ConflictException) {
            }

            $stored = $client->forwards()->update($domain->id, $host, new HttpForward($host, 'https://new.example.com'));
            self::assertSame('https://new.example.com', $stored->url);                              // 200

            // 412: the host is the forward's identity and cannot be changed.
            try {
                $client->forwards()->update($domain->id, $host, new HttpForward($host . '-renamed', 'https://x.example.com'));
                self::fail('Expected 412 when changing the host.');
            } catch (PreconditionFailedException) {
            }
        } finally {
            $client->forwards()->delete($domain->id, $host);                                        // 204
        }
    }
    */

    // --- invoices ----------------------------------------------------------

    public function testInvoices(): void
    {
        $client = $this->client();
        $invoices = $client->invoices()->list();                                 // 200
        self::assertIsArray($invoices);

        if ($invoices !== []) {
            self::assertSame($invoices[0]->id, $client->invoices()->get($invoices[0]->id)->id);  // 200
        }

        try {
            $client->invoices()->get(self::MISSING_RECORD);
            self::fail('Expected 404.');
        } catch (NotFoundException) {                                            // 404
        }

        $this->expectException(UnauthorizedException::class);                    // 401
        $this->unauthorised()->invoices()->list();
    }

    // --- dyndns ------------------------------------------------------------

    public function testDynDns(): void
    {
        $domain = $this->writableDomain();
        $client = $this->client();
        $host = self::testHost();

        $client->dynDns()->update($host . '.' . $domain->domain, '203.0.113.10');   // 204

        try {
            $records = $client->dns()->list($domain->id, host: $host, type: RecordType::A);
            self::assertCount(1, $records);
        } finally {
            foreach ($client->dns()->list($domain->id, host: $host) as $record) {
                $client->dns()->delete($domain->id, (int) $record->id);
            }
        }

        try {
            $client->dynDns()->update('nope.not-your-domain-xyz.no', '203.0.113.10');
            self::fail('Expected 404 for a domain outside the account.');
        } catch (NotFoundException) {                                            // 404
        }

        $this->expectException(UnauthorizedException::class);                    // 401
        $this->unauthorised()->dynDns()->update($host . '.' . $domain->domain);
    }
}
