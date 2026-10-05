<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Dashboard\Tests\Parser;

use Sebastka\Domeneshop\Dashboard\Exception\UnexpectedPageException;
use Sebastka\Domeneshop\Dashboard\Parser\NameserversParser;
use Sebastka\Domeneshop\Dashboard\Tests\DashboardTestCase;

final class NameserversParserTest extends DashboardTestCase
{
    private const PATH = '/admin?id=1&edit=ns';

    public function testDomeneshopNameservers(): void
    {
        $ns = NameserversParser::parse(self::fixture('nameservers'), self::PATH);

        self::assertSame(['ns1.hyp.net', 'ns2.hyp.net', 'ns3.hyp.net'], $ns->hosts);
        self::assertTrue($ns->dnssec);
    }

    public function testExternalNameserversSkipEmptySlots(): void
    {
        $ns = NameserversParser::parse(self::fixture('nameservers-external'), self::PATH);

        self::assertSame(['ns1.example.net', 'ns2.example.net'], $ns->hosts);
        self::assertTrue($ns->dnssec);
    }

    public function testAnUntickedDnssecBoxIsFalse(): void
    {
        $html = self::replaceOnce('<input type="checkbox" name="dnssec" value="Y" checked>', '<input type="checkbox" name="dnssec" value="Y">', self::fixture('nameservers'));

        self::assertFalse(NameserversParser::parse($html, self::PATH)->dnssec);
    }

    /** A missing checkbox is not "DNSSEC off". */
    public function testAMissingDnssecBoxIsUnexpected(): void
    {
        $html = self::replaceOnce('<input type="checkbox" name="dnssec" value="Y" checked>', '', self::fixture('nameservers'));

        $this->expectException(UnexpectedPageException::class);
        $this->expectExceptionMessage('DNSSEC checkbox');
        NameserversParser::parse($html, self::PATH);
    }

    public function testMissingNameserverInputsAreUnexpected(): void
    {
        $html = self::replaceOnce('name="ns2"', 'name="nameserver2"', self::fixture('nameservers'));

        $this->expectException(UnexpectedPageException::class);
        NameserversParser::parse($html, self::PATH);
    }

    public function testToArray(): void
    {
        self::assertSame(
            ['hosts' => ['ns1.hyp.net', 'ns2.hyp.net', 'ns3.hyp.net'], 'dnssec' => true],
            NameserversParser::parse(self::fixture('nameservers'), self::PATH)->toArray(),
        );
    }
}
