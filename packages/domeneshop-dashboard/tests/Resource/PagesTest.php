<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Dashboard\Tests\Resource;

use Sebastka\Domeneshop\Dashboard\Resource\Pages;
use Sebastka\Domeneshop\Dashboard\Tests\DashboardTestCase;

final class PagesTest extends DashboardTestCase
{
    /**
     * The `edit` values are what the dashboard's own URLs use — contacts,
     * ns, dnssec, glue — taken from the real admin links.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function pageProvider(): iterable
    {
        yield 'contacts' => ['contacts', 'contacts'];
        yield 'nameservers' => ['nameservers', 'ns'];
        yield 'dnssec' => ['dnssec', 'dnssec'];
        yield 'glue' => ['glue', 'glue'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pageProvider')]
    public function testEachPageRequestsTheDashboardsOwnUrl(string $name, string $edit): void
    {
        $client = $this->client(self::page());
        $client->pages()->fetch(1234567, $name);

        self::assertSame('/admin', parse_url($this->http->lastUri(), PHP_URL_PATH));
        self::assertSame(['id' => '1234567', 'edit' => $edit], $this->http->lastQuery());
    }

    public function testTheHtmlIsReturnedUntouched(): void
    {
        $body = '<table id="contacts"><tr><td>Ola</td></tr></table>';

        self::assertSame(self::signedIn($body), $this->client(self::page($body))->pages()->fetch(1, 'contacts'));
    }

    public function testAnUnknownPageIsRejectedBeforeAnyRequest(): void
    {
        $client = $this->client(self::page());

        try {
            $client->pages()->fetch(1, 'billing');
            self::fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Unknown dashboard page "billing"', $e->getMessage());
        }

        self::assertSame(0, $this->http->callCount(), 'No request should be made for an unknown page.');
    }

    public function testNamesListsEveryPage(): void
    {
        self::assertSame(['contacts', 'nameservers', 'dnssec', 'glue'], Pages::names());
    }
}
