<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Dashboard\Tests\Resource;

use GuzzleHttp\Psr7\Response;
use Sebastka\Domeneshop\Dashboard\Exception\PageUnavailableException;
use Sebastka\Domeneshop\Dashboard\Exception\UnexpectedPageException;
use Sebastka\Domeneshop\Dashboard\Tests\DashboardTestCase;

/**
 * The accessors on DashboardClient, end to end: the right page is requested,
 * and the parser's result or refusal comes back out.
 */
final class TypedResourcesTest extends DashboardTestCase
{
    private static function fixtureResponse(string $name): Response
    {
        return self::rawPage(self::fixture($name));
    }

    public function testNameservers(): void
    {
        $ns = $this->client(self::fixtureResponse('nameservers'))->nameservers()->forDomain(42);

        self::assertSame(['id' => '42', 'edit' => 'ns'], $this->http->lastQuery());
        self::assertSame(['ns1.hyp.net', 'ns2.hyp.net', 'ns3.hyp.net'], $ns->hosts);
    }

    public function testDnssec(): void
    {
        $records = $this->client(self::fixtureResponse('dnssec'))->dnssec()->forDomain(42);

        self::assertSame(['id' => '42', 'edit' => 'dnssec'], $this->http->lastQuery());
        self::assertCount(1, $records);
    }

    public function testDnssecUnavailableNamesThePage(): void
    {
        try {
            $this->client(self::fixtureResponse('dnssec-unavailable'))->dnssec()->forDomain(42);
            self::fail('Expected a PageUnavailableException.');
        } catch (PageUnavailableException $e) {
            self::assertStringContainsString('/admin?id=42&edit=dnssec', $e->getMessage());
        }
    }

    /**
     * With external nameservers the DS records are readable, and DNSSEC is in
     * effect exactly when one is published.
     */
    public function testDnssecStatusWithExternalNameservers(): void
    {
        $status = $this->client(self::fixtureResponse('dnssec'))->dnssec()->statusForDomain(42);

        self::assertTrue($status->enabled);
        self::assertFalse($status->managedByDomeneshop);
        self::assertCount(1, (array) $status->records);
        self::assertSame(1, $this->http->callCount(), 'The nameservers page is only needed for the other setup.');
    }

    public function testDnssecStatusWithAnEmptyDsEditor(): void
    {
        $status = $this->client(self::rawPage(self::dnssecWithoutRecords()))->dnssec()->statusForDomain(42);

        self::assertFalse($status->enabled);
        self::assertSame([], $status->records, 'An empty editor really does mean no DS records.');
    }

    /**
     * On Domeneshop's nameservers there is no DS editor, so the state comes
     * from the nameservers page — and records stay null rather than becoming
     * an empty list that would read as "DNSSEC is off".
     */
    public function testDnssecStatusFallsBackToTheNameserversPage(): void
    {
        $client = $this->client(self::fixtureResponse('dnssec-unavailable'), self::fixtureResponse('nameservers'));
        $status = $client->dnssec()->statusForDomain(42);

        self::assertTrue($status->managedByDomeneshop);
        self::assertTrue($status->enabled);
        self::assertNull($status->records);
        self::assertSame(2, $this->http->callCount());
        self::assertSame(['id' => '42', 'edit' => 'ns'], $this->http->lastQuery());
    }

    public function testDnssecStatusReportsItOffWhenTheBoxIsUnticked(): void
    {
        $off = self::replaceOnce('<input type="checkbox" name="dnssec" value="Y" checked>', '<input type="checkbox" name="dnssec" value="Y">', self::fixture('nameservers'));
        $client = $this->client(self::fixtureResponse('dnssec-unavailable'), self::rawPage($off));

        $status = $client->dnssec()->statusForDomain(42);

        self::assertTrue($status->managedByDomeneshop);
        self::assertFalse($status->enabled);
        self::assertNull($status->records);
    }

    /** The plain accessor still refuses, for callers that want the records or nothing. */
    public function testForDomainStillRefusesWhenThereIsNoEditor(): void
    {
        $this->expectException(PageUnavailableException::class);
        $this->client(self::fixtureResponse('dnssec-unavailable'))->dnssec()->forDomain(42);
    }

    public function testGlue(): void
    {
        self::assertSame([], $this->client(self::fixtureResponse('glue'))->glue()->forDomain(42));
        self::assertSame(['id' => '42', 'edit' => 'glue'], $this->http->lastQuery());
    }

    public function testContacts(): void
    {
        $contacts = $this->client(self::fixtureResponse('contacts-generic'))->contacts()->forDomain(42);

        self::assertSame(['id' => '42', 'edit' => 'contacts'], $this->http->lastQuery());
        self::assertSame('Ola Nordmann', $contacts->owner->name);
    }

    /** A page swapped for another — here, a stale redirect — is never parsed as the wrong thing. */
    public function testTheWrongPageIsRefused(): void
    {
        $this->expectException(UnexpectedPageException::class);
        $this->client(self::fixtureResponse('contacts-no'))->nameservers()->forDomain(42);
    }
}
