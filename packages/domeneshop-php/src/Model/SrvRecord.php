<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Model;

/**
 * An `SRV` record — advertises the host and port of a service.
 *
 * The `host` follows the `_service._protocol` convention, e.g. `_sip._tcp`.
 */
final class SrvRecord extends DnsRecord
{
    /**
     * @param string $data     The target hostname, e.g. `sip.example.com`.
     * @param int    $priority Preference; lower values are tried first.
     * @param int    $weight   Relative weight among records sharing a priority.
     * @param int    $port     The port the service listens on.
     */
    public function __construct(
        string $host,
        public readonly string $data,
        public readonly int $priority,
        public readonly int $weight,
        public readonly int $port,
        ?int $ttl = null,
        ?int $id = null,
    ) {
        parent::__construct($host, $ttl, $id);
    }

    public function type(): RecordType
    {
        return RecordType::SRV;
    }

    protected function payload(): array
    {
        return [
            'data' => $this->data,
            'priority' => $this->priority,
            'weight' => $this->weight,
            'port' => $this->port,
        ];
    }
}
