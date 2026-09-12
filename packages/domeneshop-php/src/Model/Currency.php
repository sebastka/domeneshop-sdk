<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Model;

/** Currencies Domeneshop invoices in. */
enum Currency: string
{
    case NOK = 'NOK';
    case SEK = 'SEK';
    case DKK = 'DKK';
    case GBP = 'GBP';
    case USD = 'USD';
}
