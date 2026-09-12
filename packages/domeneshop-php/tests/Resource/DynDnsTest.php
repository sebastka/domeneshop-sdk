<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Tests\Resource;

use Sebastka\Domeneshop\Tests\DomeneshopTestCase;

final class DynDnsTest extends DomeneshopTestCase
{
    public function testUpdateWithoutAnAddressLetsTheApiUseTheClientIp(): void
    {
        $client = $this->client(self::noContent());
        $client->dynDns()->update('home.example.com');

        self::assertSame('/v0/dyndns/update', $this->http->lastPath());
        self::assertSame(['hostname' => 'home.example.com'], $this->http->lastQuery());
    }

    public function testUpdateSendsAnExplicitAddress(): void
    {
        $client = $this->client(self::noContent());
        $client->dynDns()->update('home.example.com', '203.0.113.10');

        self::assertSame(
            ['hostname' => 'home.example.com', 'myip' => '203.0.113.10'],
            $this->http->lastQuery(),
        );
    }

    public function testUpdateJoinsListsWithCommas(): void
    {
        $client = $this->client(self::noContent());
        $client->dynDns()->update(['a.example.com', 'b.example.com'], ['203.0.113.10', '2001:db8::1']);

        self::assertSame(
            ['hostname' => 'a.example.com,b.example.com', 'myip' => '203.0.113.10,2001:db8::1'],
            $this->http->lastQuery(),
        );
    }

    public function testUpdateTrimsAndDropsBlanksInACommaString(): void
    {
        $client = $this->client(self::noContent());
        $client->dynDns()->update('a.example.com, b.example.com, ');

        self::assertSame(['hostname' => 'a.example.com,b.example.com'], $this->http->lastQuery());
    }

    public function testUpdateRejectsAnEmptyHostname(): void
    {
        $client = $this->client(self::noContent());

        $this->expectException(\InvalidArgumentException::class);
        $client->dynDns()->update('   ');
    }

    public function testUpdateRejectsMoreAddressesThanTheApiAccepts(): void
    {
        $client = $this->client(self::noContent());

        $this->expectException(\InvalidArgumentException::class);
        $client->dynDns()->update('home.example.com', array_fill(0, 10, '203.0.113.10'));
    }
}
