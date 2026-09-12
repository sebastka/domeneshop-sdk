<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Model;

/**
 * A `TXT` record — freeform text, commonly SPF, DKIM and domain-verification tokens.
 */
final class TxtRecord extends DnsRecord
{
    /**
     * @param string $data Freeform text, e.g. `v=spf1 include:_spf.domeneshop.no ~all`.
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
        return RecordType::TXT;
    }

    protected function payload(): array
    {
        return ['data' => $this->data];
    }
}
