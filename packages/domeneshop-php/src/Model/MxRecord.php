<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Model;

/**
 * An `MX` record — names a mail exchanger for the host, with a preference.
 */
final class MxRecord extends DnsRecord
{
    /**
     * @param string $data     The target MX host, e.g. `mx.example.com`.
     * @param int    $priority Preference; lower values are usually tried first,
     *                         though nothing guarantees it.
     */
    public function __construct(
        string $host,
        public readonly string $data,
        public readonly int $priority,
        ?int $ttl = null,
        ?int $id = null,
    ) {
        parent::__construct($host, $ttl, $id);
    }

    public function type(): RecordType
    {
        return RecordType::MX;
    }

    protected function payload(): array
    {
        return ['data' => $this->data, 'priority' => $this->priority];
    }
}
