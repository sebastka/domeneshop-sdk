<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Dashboard\Model;

/**
 * A domain's DNSSEC state, whichever way the domain is set up.
 *
 * The dashboard answers this in two different places, and which one applies
 * depends on who runs the nameservers:
 *
 *  - **External nameservers.** You publish the DS records, so the dashboard has
 *    an editor for them, and {@see self::$records} lists what it holds.
 *  - **Domeneshop's own nameservers.** Domeneshop signs the zone and publishes
 *    the DS records itself. There is no editor — the page is not served at all
 *    — and all the dashboard exposes is the "Bruk DNSSEC" checkbox on the
 *    nameservers page.
 *
 * The distinction is kept rather than flattened: `records` is null in the second
 * case, never an empty list, because "Domeneshop has not told us" and "there are
 * none" are different answers and only one of them is safe to act on.
 */
final class DnssecStatus
{
    /**
     * @param bool                 $enabled             Whether DNSSEC is in effect. Read from the
     *                                                  checkbox when Domeneshop manages it, and
     *                                                  otherwise from whether any DS record is published.
     * @param bool                 $managedByDomeneshop Whether Domeneshop publishes the DS records.
     * @param list<DsRecord>|null  $records             The DS records, or null when they are Domeneshop's to manage.
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly bool $managedByDomeneshop,
        public readonly ?array $records,
    ) {
    }

    /** @return array{enabled: bool, managed_by_domeneshop: bool, records: list<array<string, mixed>>|null} */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'managed_by_domeneshop' => $this->managedByDomeneshop,
            'records' => $this->records === null
                ? null
                : array_map(static fn (DsRecord $record): array => $record->toArray(), $this->records),
        ];
    }
}
