<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Model;

/** The DNS record types the Domeneshop API can create and return. */
enum RecordType: string
{
    case A = 'A';
    case AAAA = 'AAAA';
    case CNAME = 'CNAME';
    case MX = 'MX';
    case SRV = 'SRV';
    case TLSA = 'TLSA';
    case TXT = 'TXT';
}
