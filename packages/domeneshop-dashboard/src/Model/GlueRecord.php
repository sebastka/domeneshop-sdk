<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Dashboard\Model;

/**
 * A glue record: an address for a nameserver under the domain itself.
 *
 * The shape follows the dashboard's "add" row — one host, one IP. No existing
 * glue record has been observed yet, so the parser does not produce these; see
 * {@see \Sebastka\Domeneshop\Dashboard\Parser\GlueParser}.
 */
final class GlueRecord
{
    /**
     * @param string $host The nameserver's full hostname, e.g. `ns1.example.no`.
     * @param string $ip   Its IPv4 or IPv6 address.
     */
    public function __construct(
        public readonly string $host,
        public readonly string $ip,
    ) {
    }

    /** @return array{host: string, ip: string} */
    public function toArray(): array
    {
        return ['host' => $this->host, 'ip' => $this->ip];
    }
}
