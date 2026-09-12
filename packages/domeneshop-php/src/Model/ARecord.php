<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Model;

/**
 * An `A` record — maps a host to an IPv4 address.
 */
final class ARecord extends DnsRecord
{
    /**
     * @param string $data An IPv4 address, e.g. `203.0.113.10`.
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
        return RecordType::A;
    }

    protected function payload(): array
    {
        return ['data' => $this->data];
    }
}
