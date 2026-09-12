<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Tests\Resource;

use Sebastka\Domeneshop\Model\DomainStatus;
use Sebastka\Domeneshop\Model\Webhotel;
use Sebastka\Domeneshop\Tests\DomeneshopTestCase;

final class DomainsTest extends DomeneshopTestCase
{
    /** @return array<string, mixed> */
    private static function payload(int $id = 1, string $name = 'example.com'): array
    {
        return [
            'id' => $id,
            'domain' => $name,
            'expiry_date' => '2027-01-01',
            'registered_date' => '2019-01-01',
            'renew' => true,
            'registrant' => 'Ola Nordmann',
            'status' => 'active',
            'nameservers' => ['ns1.hyp.net', 'ns2.hyp.net'],
            'services' => ['registrar' => true, 'dns' => true, 'email' => false, 'webhotel' => 'websmall'],
        ];
    }

    public function testListMapsDomains(): void
    {
        $client = $this->client(self::json([self::payload()]));

        $domains = $client->domains()->list();

        self::assertCount(1, $domains);
        self::assertSame('/v0/domains', $this->http->lastPath());
        self::assertSame('GET', $this->http->lastMethod());
        self::assertSame(1, $domains[0]->id);
        self::assertSame('example.com', $domains[0]->domain);
        self::assertTrue($domains[0]->renew);
        self::assertSame(DomainStatus::Active, $domains[0]->status);
        self::assertSame(['ns1.hyp.net', 'ns2.hyp.net'], $domains[0]->nameservers);
        self::assertSame(Webhotel::WebSmall, $domains[0]->services?->webhotel);
        self::assertTrue($domains[0]->services?->dns);
        self::assertFalse($domains[0]->services?->email);
    }

    public function testListForwardsTheDomainFilter(): void
    {
        $client = $this->client(self::json([]));
        $client->domains()->list('example');

        self::assertSame(['domain' => 'example'], $this->http->lastQuery());
    }

    public function testListOmitsANullFilter(): void
    {
        $client = $this->client(self::json([]));
        $client->domains()->list();

        self::assertSame([], $this->http->lastQuery());
    }

    public function testGetFetchesById(): void
    {
        $client = $this->client(self::json(self::payload(42)));

        self::assertSame(42, $client->domains()->get(42)->id);
        self::assertSame('/v0/domains/42', $this->http->lastPath());
    }

    public function testFindByNameMatchesExactlyNotBySubstring(): void
    {
        $client = $this->client(self::json([
            self::payload(1, 'myexample.com'),
            self::payload(2, 'example.com'),
        ]));

        $found = $client->domains()->findByName('example.com');

        self::assertSame(2, $found?->id);
        self::assertSame(['domain' => 'example.com'], $this->http->lastQuery());
    }

    public function testFindByNameReturnsNullWhenOnlySubstringMatches(): void
    {
        $client = $this->client(self::json([self::payload(1, 'myexample.com')]));

        self::assertNull($client->domains()->findByName('example.com'));
    }

    public function testUnknownStatusBecomesNullRatherThanFailing(): void
    {
        $client = $this->client(self::json([['id' => 1, 'domain' => 'x.no', 'status' => 'brandNewStatus']]));

        self::assertNull($client->domains()->list()[0]->status);
    }

    public function testRawPayloadIsPreserved(): void
    {
        $client = $this->client(self::json([self::payload() + ['some_new_field' => 'value']]));

        self::assertSame('value', $client->domains()->list()[0]->raw['some_new_field']);
    }
}
