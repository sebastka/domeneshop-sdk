<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Model;

/**
 * An invoice or credit note. Only the past three years are available.
 *
 * The complete decoded payload is kept in {@see self::$raw} so callers can read
 * fields this DTO does not model yet.
 */
final class Invoice
{
    /**
     * @param int                  $amount The total, in the currency's major unit
     *                                     (the API types this as an integer).
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly int $id,
        public readonly ?InvoiceType $type,
        public readonly int $amount,
        public readonly ?Currency $currency,
        public readonly ?string $dueDate,
        public readonly ?string $issuedDate,
        public readonly ?string $paidDate,
        public readonly ?InvoiceStatus $status,
        public readonly ?string $url,
        public readonly array $raw,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $type = $data['type'] ?? null;
        $currency = $data['currency'] ?? null;
        $status = $data['status'] ?? null;

        return new self(
            id: (int) ($data['id'] ?? 0),
            type: \is_string($type) ? InvoiceType::tryFrom($type) : null,
            amount: (int) ($data['amount'] ?? 0),
            currency: \is_string($currency) ? Currency::tryFrom($currency) : null,
            // due_date is only set on invoices, paid_date only once paid.
            dueDate: isset($data['due_date']) ? (string) $data['due_date'] : null,
            issuedDate: isset($data['issued_date']) ? (string) $data['issued_date'] : null,
            paidDate: isset($data['paid_date']) ? (string) $data['paid_date'] : null,
            status: \is_string($status) ? InvoiceStatus::tryFrom($status) : null,
            url: isset($data['url']) ? (string) $data['url'] : null,
            raw: $data,
        );
    }
}
