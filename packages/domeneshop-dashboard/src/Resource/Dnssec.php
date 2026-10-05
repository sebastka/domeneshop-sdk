<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Dashboard\Resource;

use Sebastka\Domeneshop\Dashboard\Exception\PageUnavailableException;
use Sebastka\Domeneshop\Dashboard\Exception\SessionExpiredException;
use Sebastka\Domeneshop\Dashboard\Exception\UnexpectedPageException;
use Sebastka\Domeneshop\Dashboard\Model\DnssecStatus;
use Sebastka\Domeneshop\Dashboard\Model\DsRecord;
use Sebastka\Domeneshop\Dashboard\Parser\DnssecParser;

/**
 * The DS records the registrar publishes for the domain.
 *
 * Fetches the page and hands it to {@see DnssecParser}, which documents what it
 * reads and when it refuses.
 */
final class Dnssec
{
    private ?Nameservers $nameservers = null;

    public function __construct(private readonly Pages $pages)
    {
    }

    /**
     * A domain's DNSSEC state, without having to know which setup it has.
     *
     * Prefer this to {@see self::forDomain()} unless you specifically want the
     * DS records and want a domain that has no editor to be an error. It costs
     * one request in the usual case, and a second one — the nameservers page —
     * only when Domeneshop turns out to manage the DS records itself.
     *
     * @throws SessionExpiredException when the session cookie is not accepted.
     * @throws UnexpectedPageException when a page is not what the parser expects.
     */
    public function statusForDomain(int $domainId): DnssecStatus
    {
        try {
            $records = $this->forDomain($domainId);
        } catch (PageUnavailableException) {
            // No DS editor: Domeneshop signs the zone. All the dashboard says
            // then is whether DNSSEC is on, over on the nameservers page.
            $this->nameservers ??= new Nameservers($this->pages);

            return new DnssecStatus(
                enabled: $this->nameservers->forDomain($domainId)->dnssec,
                managedByDomeneshop: true,
                records: null,
            );
        }

        // With external nameservers, DNSSEC is in effect exactly when a DS
        // record is published in the parent zone.
        return new DnssecStatus(
            enabled: $records !== [],
            managedByDomeneshop: false,
            records: $records,
        );
    }

    /**
     * @return list<DsRecord>
     *
     * @throws SessionExpiredException when the session cookie is not accepted.
     * @throws PageUnavailableException when the dashboard has no such page for this domain.
     * @throws UnexpectedPageException when the page is not what the parser expects.
     */
    public function forDomain(int $domainId): array
    {
        return DnssecParser::parse(
            $this->pages->fetch($domainId, 'dnssec'),
            Pages::path($domainId, 'dnssec'),
        );
    }
}
