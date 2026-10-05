<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Dashboard\Model;

/**
 * A domain's delegation, as set on the dashboard's nameservers page.
 *
 * The API's domain object lists nameservers too, but read-only and without the
 * DNSSEC flag. This is the registrar-side setting itself.
 */
final class Nameservers
{
    /**
     * @param list<string> $hosts  The nameservers, in the dashboard's order. The
     *                             page has six slots; empty ones are omitted.
     * @param bool         $dnssec Whether "Bruk DNSSEC" is ticked. For a domain on
     *                             Domeneshop's nameservers this is what turns
     *                             signing on; with external nameservers the DS
     *                             records themselves are on the DNSSEC page.
     */
    public function __construct(
        public readonly array $hosts,
        public readonly bool $dnssec,
    ) {
    }

    /** @return array{hosts: list<string>, dnssec: bool} */
    public function toArray(): array
    {
        return ['hosts' => $this->hosts, 'dnssec' => $this->dnssec];
    }
}
