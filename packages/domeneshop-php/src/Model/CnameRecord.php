<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Model;

/**
 * A `CNAME` record — aliases a host to another hostname.
 */
final class CnameRecord extends DnsRecord
{
    /**
     * @param string $data The target hostname, e.g. `www.example.com`.
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
        return RecordType::CNAME;
    }

    protected function payload(): array
    {
        return ['data' => $this->data];
    }
}
