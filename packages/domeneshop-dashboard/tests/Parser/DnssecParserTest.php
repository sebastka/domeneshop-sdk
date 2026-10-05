<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Dashboard\Tests\Parser;

use Sebastka\Domeneshop\Dashboard\Exception\PageUnavailableException;
use Sebastka\Domeneshop\Dashboard\Exception\UnexpectedPageException;
use Sebastka\Domeneshop\Dashboard\Model\DsRecord;
use Sebastka\Domeneshop\Dashboard\Parser\DnssecParser;
use Sebastka\Domeneshop\Dashboard\Tests\DashboardTestCase;

final class DnssecParserTest extends DashboardTestCase
{
    private const PATH = '/admin?id=1&edit=dnssec';

    private const DIGEST = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    public function testReadsTheExistingRecordAndSkipsTheAddRow(): void
    {
        $records = DnssecParser::parse(self::fixture('dnssec'), self::PATH);

        self::assertEquals([new DsRecord(2371, 13, 2, self::DIGEST)], $records);
    }

    /**
     * The add row has an empty text `keytag` and pre-selected algorithm and
     * digest type. Mistaking it for a record would invent a DS record.
     */
    public function testTheAddRowAloneMeansNoRecords(): void
    {
        self::assertSame([], DnssecParser::parse(self::dnssecWithoutRecords(), self::PATH));
    }

    /**
     * For a domain on Domeneshop's nameservers, the dashboard answers
     * edit=dnssec with the domain overview.
     */
    public function testTheOverviewFallbackIsUnavailableNotEmpty(): void
    {
        try {
            DnssecParser::parse(self::fixture('dnssec-unavailable'), self::PATH);
            self::fail('Expected a PageUnavailableException.');
        } catch (PageUnavailableException $e) {
            self::assertStringContainsString("Domeneshop's own nameservers", $e->getMessage());
        }
    }

    public function testAnotherPageIsUnexpectedNotUnavailable(): void
    {
        $this->expectException(UnexpectedPageException::class);
        DnssecParser::parse(self::fixture('nameservers'), self::PATH);
    }

    public function testAChangedHeaderIsUnexpected(): void
    {
        $html = self::replaceOnce('<th align=right>DigestType</th>', '<th align=right>Digest type</th>', self::fixture('dnssec'));

        $this->expectException(UnexpectedPageException::class);
        DnssecParser::parse($html, self::PATH);
    }

    public function testAnUnrecognisedRowIsRefused(): void
    {
        $html = self::replaceOnce('<form method="POST"><tr><td bgcolor="#D2E5FF"', '<tr><td>2371</td><td>13</td></tr><form method="POST"><tr><td bgcolor="#D2E5FF"', self::fixture('dnssec'));

        $this->expectException(UnexpectedPageException::class);
        $this->expectExceptionMessage('does not recognise');
        DnssecParser::parse($html, self::PATH);
    }

    public function testAMissingAddRowIsUnexpected(): void
    {
        $html = self::replaceOnce('name="add"', 'name="create"', self::fixture('dnssec'));

        $this->expectException(UnexpectedPageException::class);
        DnssecParser::parse($html, self::PATH);
    }

    public function testANonNumericKeytagIsUnexpected(): void
    {
        $html = self::replaceOnce('<input type="hidden" name="keytag" value="2371">', '<input type="hidden" name="keytag" value="abc">', self::fixture('dnssec'));

        $this->expectException(UnexpectedPageException::class);
        DnssecParser::parse($html, self::PATH);
    }
}
