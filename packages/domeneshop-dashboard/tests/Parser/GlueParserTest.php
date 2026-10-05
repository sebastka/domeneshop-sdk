<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Dashboard\Tests\Parser;

use Sebastka\Domeneshop\Dashboard\Exception\UnexpectedPageException;
use Sebastka\Domeneshop\Dashboard\Parser\GlueParser;
use Sebastka\Domeneshop\Dashboard\Tests\DashboardTestCase;

final class GlueParserTest extends DashboardTestCase
{
    private const PATH = '/admin?id=1&edit=glue';

    public function testTheEmptyTableIsNoRecords(): void
    {
        self::assertSame([], GlueParser::parse(self::fixture('glue'), self::PATH));
    }

    /**
     * No existing glue record has been observed. A page with one must be
     * refused, not returned as an empty list and not guessed at.
     */
    public function testAnyRecordRowIsRefusedRatherThanGuessed(): void
    {
        $row = '<form method="POST"><tr><td>ns1.example2.no</td><td><input type="hidden" name="host" value="ns1">'
            . '<input type="text" name="ip" value="192.0.2.1"></td><td><input type=image name="delete"></td></tr></form>';
        $html = self::replaceOnce('<tr><th>Vertsnavn (hostname)</th><th>IP</th></tr>', '<tr><th>Vertsnavn (hostname)</th><th>IP</th></tr>' . $row, self::fixture('glue'));

        try {
            GlueParser::parse($html, self::PATH);
            self::fail('Expected an UnexpectedPageException.');
        } catch (UnexpectedPageException $e) {
            self::assertStringContainsString('glue records', $e->getMessage());
        }
    }

    public function testAChangedHeaderIsUnexpected(): void
    {
        $html = self::replaceOnce('<th>IP</th>', '<th>Address</th>', self::fixture('glue'));

        $this->expectException(UnexpectedPageException::class);
        GlueParser::parse($html, self::PATH);
    }

    public function testAnotherPageIsUnexpected(): void
    {
        $this->expectException(UnexpectedPageException::class);
        GlueParser::parse(self::fixture('dnssec'), self::PATH);
    }
}
