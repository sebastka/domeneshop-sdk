<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Tests\Resource;

use Sebastka\Domeneshop\Model\ARecord;
use Sebastka\Domeneshop\Model\AaaaRecord;
use Sebastka\Domeneshop\Model\CnameRecord;
use Sebastka\Domeneshop\Model\MxRecord;
use Sebastka\Domeneshop\Model\RecordType;
use Sebastka\Domeneshop\Model\SrvRecord;
use Sebastka\Domeneshop\Model\TlsaRecord;
use Sebastka\Domeneshop\Model\TxtRecord;
use Sebastka\Domeneshop\Tests\DomeneshopTestCase;

final class DnsTest extends DomeneshopTestCase
{
    public function testListMapsEachTypeToItsOwnClass(): void
    {
        $client = $this->client(self::json([
            ['id' => 1, 'host' => '@', 'ttl' => 3600, 'type' => 'A', 'data' => '203.0.113.10'],
            ['id' => 2, 'host' => '@', 'ttl' => 3600, 'type' => 'AAAA', 'data' => '2001:db8::1'],
            ['id' => 3, 'host' => 'www', 'ttl' => 3600, 'type' => 'CNAME', 'data' => 'example.com'],
            ['id' => 4, 'host' => '@', 'ttl' => 3600, 'type' => 'MX', 'data' => 'mx.example.com', 'priority' => 10],
            ['id' => 5, 'host' => '_sip._tcp', 'ttl' => 3600, 'type' => 'SRV', 'data' => 'sip.example.com', 'priority' => 10, 'weight' => 100, 'port' => 5060],
            ['id' => 6, 'host' => '_443._tcp', 'ttl' => 3600, 'type' => 'TLSA', 'data' => 'ABCD', 'usage' => 3, 'selector' => 1, 'dtype' => 1],
            ['id' => 7, 'host' => '@', 'ttl' => 3600, 'type' => 'TXT', 'data' => 'v=spf1 ~all'],
        ]));

        $records = $client->dns()->list(1);

        self::assertSame('/v0/domains/1/dns', $this->http->lastPath());
        self::assertInstanceOf(ARecord::class, $records[0]);
        self::assertInstanceOf(AaaaRecord::class, $records[1]);
        self::assertInstanceOf(CnameRecord::class, $records[2]);
        self::assertInstanceOf(MxRecord::class, $records[3]);
        self::assertInstanceOf(SrvRecord::class, $records[4]);
        self::assertInstanceOf(TlsaRecord::class, $records[5]);
        self::assertInstanceOf(TxtRecord::class, $records[6]);

        self::assertSame(10, $records[3]->priority);
        self::assertSame(5060, $records[4]->port);
        self::assertSame(100, $records[4]->weight);
        self::assertSame(3, $records[5]->usage);
        self::assertSame(1, $records[5]->dtype);
        self::assertSame(1, $records[0]->id);
        self::assertSame(3600, $records[0]->ttl);
    }

    public function testListForwardsHostAndTypeFilters(): void
    {
        $client = $this->client(self::json([]));
        $client->dns()->list(7, host: 'www', type: RecordType::A);

        self::assertSame(['host' => 'www', 'type' => 'A'], $this->http->lastQuery());
    }

    public function testGetFetchesOneRecord(): void
    {
        $client = $this->client(self::json(['id' => 9, 'host' => '@', 'type' => 'A', 'data' => '203.0.113.10']));

        $record = $client->dns()->get(3, 9);

        self::assertSame('/v0/domains/3/dns/9', $this->http->lastPath());
        self::assertInstanceOf(ARecord::class, $record);
        self::assertSame('203.0.113.10', $record->data);
    }

    public function testCreateSendsPayloadAndReturnsNewId(): void
    {
        $client = $this->client(self::json(['id' => 55], 201));

        $id = $client->dns()->create(3, new ARecord('www', '203.0.113.10', ttl: 300));

        self::assertSame(55, $id);
        self::assertSame('POST', $this->http->lastMethod());
        self::assertSame('/v0/domains/3/dns', $this->http->lastPath());
        self::assertSame(
            ['host' => 'www', 'type' => 'A', 'ttl' => 300, 'data' => '203.0.113.10'],
            $this->http->lastBody(),
        );
        self::assertSame('application/json', $this->http->lastHeader('Content-Type'));
    }

    public function testCreateOmitsTtlWhenNotSetSoTheApiDefaultApplies(): void
    {
        $client = $this->client(self::json(['id' => 1], 201));
        $client->dns()->create(3, new TxtRecord('@', 'v=spf1 ~all'));

        self::assertArrayNotHasKey('ttl', $this->http->lastBody());
    }

    public function testCreateSerialisesSrvExtras(): void
    {
        $client = $this->client(self::json(['id' => 1], 201));
        $client->dns()->create(3, new SrvRecord('_sip._tcp', 'sip.example.com', 10, 100, 5060));

        self::assertSame(
            ['host' => '_sip._tcp', 'type' => 'SRV', 'data' => 'sip.example.com', 'priority' => 10, 'weight' => 100, 'port' => 5060],
            $this->http->lastBody(),
        );
    }

    public function testCreateSerialisesTlsaExtras(): void
    {
        $client = $this->client(self::json(['id' => 1], 201));
        $client->dns()->create(3, new TlsaRecord(
            '_443._tcp',
            '7FF8B87BB269715FEF08A0F4F0033D7A2F3B680470A30E878CEB2D3E244AF3EA',
            TlsaRecord::USAGE_DANE_EE,
            TlsaRecord::SELECTOR_SUBJECT_PUBLIC_KEY,
            TlsaRecord::DTYPE_SHA256,
        ));

        $body = $this->http->lastBody();
        self::assertSame(3, $body['usage']);
        self::assertSame(1, $body['selector']);
        self::assertSame(1, $body['dtype']);
        self::assertSame('TLSA', $body['type']);
    }

    public function testUpdatePutsTheFullRecord(): void
    {
        $client = $this->client(self::noContent());
        $client->dns()->update(3, 9, new MxRecord('@', 'mx2.example.com', 20, ttl: 600));

        self::assertSame('PUT', $this->http->lastMethod());
        self::assertSame('/v0/domains/3/dns/9', $this->http->lastPath());
        self::assertSame(
            ['host' => '@', 'type' => 'MX', 'ttl' => 600, 'data' => 'mx2.example.com', 'priority' => 20],
            $this->http->lastBody(),
        );
    }

    public function testUpdateNeverSendsTheReadOnlyId(): void
    {
        $client = $this->client(self::noContent());
        $client->dns()->update(3, 9, new ARecord('@', '203.0.113.10', id: 9));

        self::assertArrayNotHasKey('id', $this->http->lastBody());
    }

    public function testDeleteIssuesADeleteRequest(): void
    {
        $client = $this->client(self::noContent());
        $client->dns()->delete(3, 9);

        self::assertSame('DELETE', $this->http->lastMethod());
        self::assertSame('/v0/domains/3/dns/9', $this->http->lastPath());
    }
}
