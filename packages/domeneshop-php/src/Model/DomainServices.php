<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Model;

/** Which Domeneshop services are active for a domain. */
final class DomainServices
{
    public function __construct(
        public readonly bool $registrar,
        public readonly bool $dns,
        public readonly bool $email,
        public readonly Webhotel $webhotel,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $webhotel = $data['webhotel'] ?? null;

        return new self(
            registrar: (bool) ($data['registrar'] ?? false),
            dns: (bool) ($data['dns'] ?? false),
            email: (bool) ($data['email'] ?? false),
            webhotel: (\is_string($webhotel) ? Webhotel::tryFrom($webhotel) : null) ?? Webhotel::None,
        );
    }
}
