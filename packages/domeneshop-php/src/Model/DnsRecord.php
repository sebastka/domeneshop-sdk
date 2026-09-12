<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Model;

/**
 * A single DNS record.
 *
 * The API models records as a `oneOf` over seven shapes that share `id`, `host`
 * and `ttl` but differ in their payload — MX adds a priority, SRV adds priority,
 * weight and port, TLSA adds usage, selector and dtype. Rather than one wide
 * class with mostly-null properties, each shape is its own subclass, so the
 * fields a record type requires are exactly the fields its constructor demands:
 *
 *     new ARecord(host: '@',   data: '203.0.113.10');
 *     new MxRecord(host: '@',  data: 'mx.example.com', priority: 10);
 *     new SrvRecord(host: '_sip._tcp', data: 'sip.example.com', priority: 10, weight: 100, port: 5060);
 *
 * {@see self::fromArray()} dispatches an API payload to the right subclass.
 */
abstract class DnsRecord
{
    /** TTL bounds the API enforces; a TTL must also be a whole number of minutes. */
    public const MIN_TTL = 60;
    public const MAX_TTL = 604800;

    /**
     * @param string   $host The host/subdomain the record applies to; `@` is the zone apex.
     * @param int|null $ttl  TTL in seconds, or null to let the API apply its default (3600).
     * @param int|null $id   Server-assigned record id; null for a record you are about to create.
     */
    public function __construct(
        public readonly string $host,
        public readonly ?int $ttl = null,
        public readonly ?int $id = null,
    ) {
        if ($ttl !== null && ($ttl < self::MIN_TTL || $ttl > self::MAX_TTL || $ttl % 60 !== 0)) {
            throw new \InvalidArgumentException(\sprintf(
                'TTL must be a multiple of 60 between %d and %d seconds, got %d.',
                self::MIN_TTL,
                self::MAX_TTL,
                $ttl,
            ));
        }
    }

    abstract public function type(): RecordType;

    /**
     * The type-specific half of the wire payload.
     *
     * @return array<string, mixed>
     */
    abstract protected function payload(): array;

    /**
     * The complete wire payload for a create or update.
     *
     * The `id` is deliberately omitted: it is carried in the URL, and the API
     * marks it read-only.
     *
     * @return array<string, mixed>
     */
    final public function toArray(): array
    {
        $base = ['host' => $this->host, 'type' => $this->type()->value];
        if ($this->ttl !== null) {
            $base['ttl'] = $this->ttl;
        }

        return $base + $this->payload();
    }

    /**
     * Build the concrete record class for a decoded API payload.
     *
     * @param array<string, mixed> $data
     *
     * @throws \InvalidArgumentException when `type` is missing or not a type we model.
     */
    final public static function fromArray(array $data): self
    {
        $rawType = $data['type'] ?? null;
        if (! \is_string($rawType)) {
            throw new \InvalidArgumentException('DNS record payload has no `type` field.');
        }

        $type = RecordType::tryFrom($rawType)
            ?? throw new \InvalidArgumentException(\sprintf('Unsupported DNS record type "%s".', $rawType));

        $host = (string) ($data['host'] ?? '');
        $ttl = isset($data['ttl']) ? (int) $data['ttl'] : null;
        $id = isset($data['id']) ? (int) $data['id'] : null;
        $value = (string) ($data['data'] ?? '');

        return match ($type) {
            RecordType::A => new ARecord($host, $value, $ttl, $id),
            RecordType::AAAA => new AaaaRecord($host, $value, $ttl, $id),
            RecordType::CNAME => new CnameRecord($host, $value, $ttl, $id),
            RecordType::TXT => new TxtRecord($host, $value, $ttl, $id),
            RecordType::MX => new MxRecord($host, $value, (int) ($data['priority'] ?? 0), $ttl, $id),
            RecordType::SRV => new SrvRecord(
                $host,
                $value,
                (int) ($data['priority'] ?? 0),
                (int) ($data['weight'] ?? 0),
                (int) ($data['port'] ?? 0),
                $ttl,
                $id,
            ),
            RecordType::TLSA => new TlsaRecord(
                $host,
                $value,
                (int) ($data['usage'] ?? 0),
                (int) ($data['selector'] ?? 0),
                (int) ($data['dtype'] ?? 0),
                $ttl,
                $id,
            ),
        };
    }
}
