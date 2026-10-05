<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Dashboard\Model;

/**
 * A DS record the registrar publishes in the parent zone (RFC 4034 §5).
 *
 * Only domains on external nameservers have these on the dashboard; for
 * Domeneshop-hosted DNS the page does not exist — see
 * {@see \Sebastka\Domeneshop\Dashboard\Exception\PageUnavailableException}.
 */
final class DsRecord
{
    /**
     * @param int    $keytag     Key tag of the DNSKEY this record points at.
     * @param int    $algorithm  DNSSEC algorithm number, e.g. 13 (ECDSAP256SHA256).
     * @param int    $digestType Digest algorithm number, e.g. 2 (SHA-256).
     * @param string $digest     The digest, as hex.
     */
    public function __construct(
        public readonly int $keytag,
        public readonly int $algorithm,
        public readonly int $digestType,
        public readonly string $digest,
    ) {
    }

    /** @return array{keytag: int, algorithm: int, digest_type: int, digest: string} */
    public function toArray(): array
    {
        return [
            'keytag' => $this->keytag,
            'algorithm' => $this->algorithm,
            'digest_type' => $this->digestType,
            'digest' => $this->digest,
        ];
    }
}
