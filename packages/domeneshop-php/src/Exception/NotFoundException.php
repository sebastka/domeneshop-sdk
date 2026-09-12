<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Exception;

/**
 * HTTP 404 — the referenced entity does not exist. Inspect the request path to tell apart a missing domain, DNS record, forward or invoice.
 */
final class NotFoundException extends ApiException
{
}
