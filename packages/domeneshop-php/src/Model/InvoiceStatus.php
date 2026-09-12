<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Model;

/** Payment status of an invoice. `Settled` applies only to credit notes. */
enum InvoiceStatus: string
{
    case Unpaid = 'unpaid';
    case Paid = 'paid';
    case Settled = 'settled';
}
