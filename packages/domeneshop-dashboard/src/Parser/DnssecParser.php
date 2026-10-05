<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Dashboard\Parser;

use Sebastka\Domeneshop\Dashboard\Exception\PageUnavailableException;
use Sebastka\Domeneshop\Dashboard\Exception\UnexpectedPageException;
use Sebastka\Domeneshop\Dashboard\Html\Page;
use Sebastka\Domeneshop\Dashboard\Model\DsRecord;

/**
 * Reads `/admin?id=…&edit=dnssec`: the DS records published for a domain.
 *
 * The page is one table. Each existing record is a row carrying its values in
 * hidden inputs (`keytag`, `algorithm`, `digesttype`) plus an editable `digest`,
 * with modify and delete buttons. The last row adds a new record, and uses a
 * *text* `keytag` input instead. Any row that is neither is refused.
 */
final class DnssecParser
{
    private const HEADER = ['Keytag', 'Algorithm', 'DigestType', 'Digest'];

    /**
     * @return list<DsRecord>
     *
     * @throws PageUnavailableException when the dashboard has no DS editor for this domain.
     * @throws UnexpectedPageException  when the table is not the one this parser knows.
     */
    public static function parse(string $html, string $path): array
    {
        $page = Page::parse($html, $path, 'dnssec');
        $table = $page->one('.AdminPanel table.Admin', 'the DS record table');

        $header = array_map(static fn (\Dom\Element $th): string => Page::clean($th->textContent), $page->all('th', $table));
        if ($header !== self::HEADER) {
            throw UnexpectedPageException::missing('the DS record table header (' . implode(', ', self::HEADER) . ')', $path);
        }

        $records = [];
        $sawAddRow = false;

        foreach ($page->all('tr', $table) as $row) {
            if ($row->querySelector('th') !== null) {
                continue;
            }

            if ($row->querySelector('input[type="hidden"][name="keytag"]') !== null) {
                $records[] = new DsRecord(
                    keytag: self::number($row, 'keytag', $path),
                    algorithm: self::number($row, 'algorithm', $path),
                    digestType: self::number($row, 'digesttype', $path),
                    digest: self::digest($row, $path),
                );

                continue;
            }

            if ($row->querySelector('input[name="add"]') !== null) {
                $sawAddRow = true;

                continue;
            }

            throw UnexpectedPageException::unrecognised('a DS table row', $path);
        }

        // The add row is always there. Without it, "no records" could just as
        // well mean the table moved.
        if (! $sawAddRow) {
            throw UnexpectedPageException::missing('the row for adding a DS record', $path);
        }

        return $records;
    }

    private static function number(\Dom\Element $row, string $name, string $path): int
    {
        $value = $row->querySelector(\sprintf('input[name="%s"]', $name))?->getAttribute('value');

        if ($value === null || ! ctype_digit($value)) {
            throw UnexpectedPageException::missing(\sprintf('a numeric %s on a DS record row', $name), $path);
        }

        return (int) $value;
    }

    private static function digest(\Dom\Element $row, string $path): string
    {
        $value = $row->querySelector('input[name="digest"]')?->getAttribute('value');

        if ($value === null || ! ctype_xdigit($value)) {
            throw UnexpectedPageException::missing('a hex digest on a DS record row', $path);
        }

        return strtolower($value);
    }
}
