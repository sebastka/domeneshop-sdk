<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Tests\Contract;

use PHPUnit\Framework\Attributes\Group;
use Sebastka\Domeneshop\Exception\NotFoundException;
use Sebastka\Domeneshop\Model\ARecord;
use Sebastka\Domeneshop\Model\HttpForward;
use Sebastka\Domeneshop\Model\MxRecord;
use Sebastka\Domeneshop\Model\RecordType;
use Sebastka\Domeneshop\Model\TxtRecord;

/**
 * Full create/read/update/delete cycles against the live API.
 *
 * Every host carries the {@see ContractTestCase::TEST_HOST_PREFIX} prefix and a
 * random suffix, and each test cleans up in a finally block — so a failure
 * mid-test still removes what it created.
 */
#[Group('contract')]
final class MutatingContractTest extends ContractTestCase
{
    public function testARecordLifecycle(): void
    {
        $domain = $this->writableDomain();
        $client = $this->client();
        $host = self::testHost();

        $recordId = $client->dns()->create($domain->id, new ARecord($host, '203.0.113.10', ttl: 300));

        try {
            self::assertGreaterThan(0, $recordId);

            $fetched = $client->dns()->get($domain->id, $recordId);
            self::assertInstanceOf(ARecord::class, $fetched);
            self::assertSame($host, $fetched->host);
            self::assertSame('203.0.113.10', $fetched->data);
            self::assertSame(300, $fetched->ttl);

            // A PUT is a full replacement, so send the complete record.
            $client->dns()->update($domain->id, $recordId, new ARecord($host, '203.0.113.99', ttl: 600));

            $updated = $client->dns()->get($domain->id, $recordId);
            self::assertInstanceOf(ARecord::class, $updated);
            self::assertSame('203.0.113.99', $updated->data);
            self::assertSame(600, $updated->ttl);
        } finally {
            $client->dns()->delete($domain->id, $recordId);
        }

        $this->expectException(NotFoundException::class);
        $client->dns()->get($domain->id, $recordId);
    }

    public function testTheHostAndTypeFiltersNarrowTheList(): void
    {
        $domain = $this->writableDomain();
        $client = $this->client();
        $host = self::testHost();

        $recordId = $client->dns()->create($domain->id, new TxtRecord($host, 'contract-test'));

        try {
            $byHost = $client->dns()->list($domain->id, host: $host);
            self::assertCount(1, $byHost);
            self::assertSame($host, $byHost[0]->host);

            $byType = $client->dns()->list($domain->id, host: $host, type: RecordType::TXT);
            self::assertCount(1, $byType);

            // A type the record is not should narrow it away entirely.
            self::assertSame([], $client->dns()->list($domain->id, host: $host, type: RecordType::A));
        } finally {
            $client->dns()->delete($domain->id, $recordId);
        }
    }

    /**
     * Two live behaviours the documentation does not mention:
     *
     *  - a hostname-valued record comes back **fully qualified with a trailing
     *    dot**: send `mx.example.com`, read back `mx.example.com.`;
     *  - `priority` comes back as a **JSON string** (`"42"`), though the schema
     *    types it as an integer. The SDK casts it, so callers see an int.
     */
    public function testAnMxRecordComesBackFullyQualifiedWithAnIntegerPriority(): void
    {
        $domain = $this->writableDomain();
        $client = $this->client();
        $host = self::testHost();

        $recordId = $client->dns()->create($domain->id, new MxRecord($host, 'mx.example.com', priority: 42));

        try {
            $fetched = $client->dns()->get($domain->id, $recordId);
            self::assertInstanceOf(MxRecord::class, $fetched);

            // Cast back to int by the SDK, whatever the wire says.
            self::assertSame(42, $fetched->priority);

            self::assertSame(
                'mx.example.com.',
                $fetched->data,
                'The API is expected to return hostname data fully qualified, with a trailing dot',
            );
        } finally {
            $client->dns()->delete($domain->id, $recordId);
        }
    }

    public function testTheApiAppliesItsDefaultTtlWhenWeOmitIt(): void
    {
        $domain = $this->writableDomain();
        $client = $this->client();
        $host = self::testHost();

        $recordId = $client->dns()->create($domain->id, new TxtRecord($host, 'no-explicit-ttl'));

        try {
            self::assertSame(3600, $client->dns()->get($domain->id, $recordId)->ttl);
        } finally {
            $client->dns()->delete($domain->id, $recordId);
        }
    }

    public function testDeletingAnAlreadyDeletedRecordIsA404(): void
    {
        $domain = $this->writableDomain();
        $client = $this->client();

        // Create a record and remove it, so the id we then re-delete is certainly
        // gone and was certainly ours. Guessing at a low id like 1 would risk
        // deleting a real record that happens to carry it.
        $recordId = $client->dns()->create($domain->id, new TxtRecord(self::testHost(), 'contract-test'));
        $client->dns()->delete($domain->id, $recordId);

        $this->expectException(NotFoundException::class);
        $client->dns()->delete($domain->id, $recordId);
    }

    /**
     * Creating and reading a forward works; changing or removing one does not.
     *
     * The API's per-host endpoint answers 404 for every host, so `update()` and
     * `delete()` cannot succeed — see the note on {@see \Sebastka\Domeneshop\Resource\Forwards}.
     * This test therefore **creates nothing**: a forward created here could not
     * be cleaned up afterwards, and leaving debris on a real domain is not an
     * acceptable price for a test.
     *
     * Run it with DOMENESHOP_CONTRACT_FORWARDS=1 once the endpoint is fixed.
     */
    public function testForwardLifecycle(): void
    {
        if (self::env('DOMENESHOP_CONTRACT_FORWARDS') !== '1') {
            self::markTestSkipped(
                'Creating a forward is irreversible through the API: its per-host endpoint 404s, '
                . 'so update() and delete() cannot work and the forward would have to be removed by hand '
                . 'in the web interface. Set DOMENESHOP_CONTRACT_FORWARDS=1 to run this anyway.',
            );
        }

        $domain = $this->writableDomain();
        $client = $this->client();
        $host = self::testHost();

        $client->forwards()->create($domain->id, new HttpForward($host, 'https://example.com'));

        $fetched = $client->forwards()->get($domain->id, $host);
        self::assertSame($host, $fetched->host);
        self::assertSame('https://example.com', $fetched->url);
        self::assertFalse($fetched->frame);

        $hosts = array_map(static fn (HttpForward $f): string => $f->host, $client->forwards()->list($domain->id));
        self::assertContains($host, $hosts);

        $client->forwards()->delete($domain->id, $host);
    }

    /**
     * Reading forwards is safe and does work, so it is always exercised.
     */
    public function testListingForwardsWorksOnADomainWithDnsService(): void
    {
        $domain = $this->writableDomain();

        self::assertIsArray($this->client()->forwards()->list($domain->id));
    }

    public function testDynDnsCreatesAndUpdatesAnARecord(): void
    {
        $domain = $this->writableDomain();
        $client = $this->client();
        $host = self::testHost();
        $fqdn = $host . '.' . $domain->domain;

        // dyndns creates the record as a side effect, so there is nothing to
        // create first — but we must find it afterwards to clean up.
        $client->dynDns()->update($fqdn, '203.0.113.10');

        $recordId = null;

        try {
            $records = $client->dns()->list($domain->id, host: $host, type: RecordType::A);
            self::assertCount(1, $records, 'dyndns should have created exactly one A record');
            $recordId = $records[0]->id;
            self::assertSame('203.0.113.10', $records[0]->data);

            // A second call keeps exactly one record for the host — but it
            // **replaces** it rather than editing it, so the record id changes.
            // Anything holding the previous id is left pointing at nothing.
            $client->dynDns()->update($fqdn, '203.0.113.99');

            $after = $client->dns()->list($domain->id, host: $host, type: RecordType::A);
            self::assertCount(1, $after, 'dyndns should leave exactly one record for the host');
            self::assertSame('203.0.113.99', $after[0]->data);
            self::assertNotSame(
                $recordId,
                $after[0]->id,
                'dyndns is expected to replace the record, giving it a new id',
            );

            // Track the live id so the cleanup below removes the right record.
            $recordId = $after[0]->id;
        } finally {
            if ($recordId !== null) {
                $client->dns()->delete($domain->id, $recordId);
            }
        }
    }
}
