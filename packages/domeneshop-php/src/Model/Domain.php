<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Model;

/**
 * A domain in the account, as returned by /domains.
 *
 * The complete decoded payload is kept in {@see self::$raw} so callers can read
 * fields this DTO does not model yet without waiting for the SDK to catch up.
 */
final class Domain
{
    /**
     * @param list<string>         $nameservers
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly int $id,
        public readonly string $domain,
        public readonly ?string $expiryDate,
        public readonly ?string $registeredDate,
        public readonly bool $renew,
        public readonly string $registrant,
        public readonly ?DomainStatus $status,
        public readonly array $nameservers,
        public readonly ?DomainServices $services,
        public readonly array $raw,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $status = $data['status'] ?? null;
        $services = $data['services'] ?? null;

        return new self(
            id: (int) ($data['id'] ?? 0),
            domain: (string) ($data['domain'] ?? ''),
            expiryDate: isset($data['expiry_date']) ? (string) $data['expiry_date'] : null,
            registeredDate: isset($data['registered_date']) ? (string) $data['registered_date'] : null,
            renew: (bool) ($data['renew'] ?? false),
            registrant: (string) ($data['registrant'] ?? ''),
            // An unrecognised status becomes null rather than an error: a new
            // value upstream should not break reading the rest of the domain.
            status: \is_string($status) ? DomainStatus::tryFrom($status) : null,
            nameservers: array_values(array_map(strval(...), (array) ($data['nameservers'] ?? []))),
            services: \is_array($services) ? DomainServices::fromArray($services) : null,
            raw: $data,
        );
    }
}
