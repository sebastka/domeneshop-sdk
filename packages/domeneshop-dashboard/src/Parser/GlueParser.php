<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Dashboard\Parser;

use Sebastka\Domeneshop\Dashboard\Exception\UnexpectedPageException;
use Sebastka\Domeneshop\Dashboard\Html\Page;
use Sebastka\Domeneshop\Dashboard\Model\GlueRecord;

/**
 * Reads `/admin?id=…&edit=glue`.
 *
 * ## Deliberately incomplete
 *
 * No domain this was developed against has a glue record, so the markup of an
 * existing one has never been seen. The DNSSEC page suggests a guess — hidden
 * inputs plus modify/delete buttons — but a guess is exactly what a scraper
 * must not ship: if it were wrong, the result would be a confident, wrong list.
 *
 * So this parser recognises the empty table (its header and the "add" row) and
 * returns an empty list only then. Any other row throws, asking for the page to
 * be reported, and the parser gains real support from that fixture.
 */
final class GlueParser
{
    private const HEADER = ['Vertsnavn (hostname)', 'IP'];

    /**
     * @return list<GlueRecord> Always empty for now; see the class notes.
     *
     * @throws UnexpectedPageException when the page has glue records, or is not the one this parser knows.
     */
    public static function parse(string $html, string $path): array
    {
        $page = Page::parse($html, $path, 'glue');
        $table = $page->one('.AdminPanel table.Admin', 'the glue record table');

        $header = array_map(static fn (\Dom\Element $th): string => Page::clean($th->textContent), $page->all('th', $table));
        if ($header !== self::HEADER) {
            throw UnexpectedPageException::missing('the glue table header (' . implode(', ', self::HEADER) . ')', $path);
        }

        $sawAddRow = false;

        foreach ($page->all('tr', $table) as $row) {
            if ($row->querySelector('th') !== null) {
                continue;
            }

            if ($row->querySelector('input[name="add"]') !== null && $row->querySelector('input[name="host"]') !== null) {
                $sawAddRow = true;

                continue;
            }

            throw UnexpectedPageException::unrecognised(
                'a glue table row',
                $path,
                'This domain most likely has glue records, whose markup this package has not seen yet.',
            );
        }

        if (! $sawAddRow) {
            throw UnexpectedPageException::missing('the row for adding a glue record', $path);
        }

        return [];
    }
}
