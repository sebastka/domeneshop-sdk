<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Cli;

use Sebastka\Domeneshop\Model\DnsRecord;
use Sebastka\Domeneshop\Model\Domain;
use Sebastka\Domeneshop\Model\HttpForward;
use Sebastka\Domeneshop\Model\Invoice;

/**
 * Turns SDK models back into plain arrays for `--json` output.
 *
 * For models that keep the decoded payload (`Domain`, `Invoice`) we emit it
 * verbatim, so piping `--json` into `jq` gives exactly what the API said —
 * including any field the SDK does not model yet.
 *
 * @internal
 */
final class Format
{
    /** @return array<string, mixed> */
    public static function domain(Domain $domain): array
    {
        return $domain->raw;
    }

    /** @return array<string, mixed> */
    public static function invoice(Invoice $invoice): array
    {
        return $invoice->raw;
    }

    /** @return array<string, mixed> */
    public static function record(DnsRecord $record): array
    {
        // toArray() omits the read-only id (it belongs in the URL, not the body),
        // so put it back for display.
        return ['id' => $record->id] + $record->toArray();
    }

    /** @return array<string, mixed> */
    public static function forward(HttpForward $forward): array
    {
        return $forward->toArray();
    }

    /** Render a value for a table cell. */
    public static function cell(mixed $value): string
    {
        return match (true) {
            $value === null => '-',
            \is_bool($value) => $value ? 'yes' : 'no',
            \is_array($value) => implode(', ', array_map(self::cell(...), $value)),
            $value instanceof \BackedEnum => (string) $value->value,
            default => (string) $value,
        };
    }
}
