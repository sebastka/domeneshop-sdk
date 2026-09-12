<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Model;

/** Lifecycle status of a domain, as reported by /domains. */
enum DomainStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Deactivated = 'deactivated';
    case PendingDeleteRestorable = 'pendingDeleteRestorable';
}
