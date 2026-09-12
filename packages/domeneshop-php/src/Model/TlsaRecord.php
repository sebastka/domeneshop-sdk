<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Model;

/**
 * A `TLSA` record — pins a certificate or public key for DANE.
 *
 * The `host` follows the `_port._protocol` convention, e.g. `_443._tcp`.
 */
final class TlsaRecord extends DnsRecord
{
    /** How the hash should be interpreted. */
    public const USAGE_PKIX_TA = 0;
    public const USAGE_PKIX_EE = 1;
    public const USAGE_DANE_TA = 2;
    public const USAGE_DANE_EE = 3;

    /** What the hash was taken from. */
    public const SELECTOR_FULL_CERTIFICATE = 0;
    public const SELECTOR_SUBJECT_PUBLIC_KEY = 1;

    /** Which hashing algorithm produced the data. */
    public const DTYPE_EXACT_MATCH = 0;
    public const DTYPE_SHA256 = 1;
    public const DTYPE_SHA512 = 2;

    /**
     * @param string $data     The hash itself. Its length follows $dtype —
     *                         64 hex characters for SHA-256, 128 for SHA-512.
     * @param int    $usage    One of the USAGE_* constants.
     * @param int    $selector One of the SELECTOR_* constants.
     * @param int    $dtype    One of the DTYPE_* constants.
     */
    public function __construct(
        string $host,
        public readonly string $data,
        public readonly int $usage,
        public readonly int $selector,
        public readonly int $dtype,
        ?int $ttl = null,
        ?int $id = null,
    ) {
        parent::__construct($host, $ttl, $id);
    }

    public function type(): RecordType
    {
        return RecordType::TLSA;
    }

    protected function payload(): array
    {
        return [
            'data' => $this->data,
            'usage' => $this->usage,
            'selector' => $this->selector,
            'dtype' => $this->dtype,
        ];
    }
}
