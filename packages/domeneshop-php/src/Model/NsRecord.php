<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Model;

/**
 * An `NS` record — delegates a subdomain to other nameservers.
 *
 * Absent from the official definition, but the live API creates and returns
 * them; see NOTES.md.
 */
final class NsRecord extends DnsRecord
{
    /**
     * @param string $data The nameserver's hostname, e.g. `ns1.lab.example.com`.
     */
    public function __construct(
        string $host,
        public readonly string $data,
        ?int $ttl = null,
        ?int $id = null,
    ) {
        parent::__construct($host, $ttl, $id);
    }

    public function type(): RecordType
    {
        return RecordType::NS;
    }

    protected function payload(): array
    {
        return ['data' => $this->data];
    }
}
