<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Model;

/**
 * Whether the document is an invoice or a credit note.
 *
 * The API spells the credit-note value `credit_node` — a typo on their side
 * that we mirror verbatim on the wire, because that is what it actually sends.
 */
enum InvoiceType: string
{
    case Invoice = 'invoice';
    case CreditNote = 'credit_node';
}
