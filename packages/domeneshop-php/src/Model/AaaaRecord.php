<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Model;

/**
 * An `AAAA` record — maps a host to an IPv6 address.
 */
final class AaaaRecord extends DnsRecord
{
    /**
     * @param string $data An IPv6 address, e.g. `2001:db8::1`.
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
        return RecordType::AAAA;
    }

    protected function payload(): array
    {
        return ['data' => $this->data];
    }
}
