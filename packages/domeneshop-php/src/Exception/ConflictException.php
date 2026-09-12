<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Exception;

/**
 * HTTP 409 — the write collides with existing data. Creating an HTTP forward raises this when the host already has a forward or an A/AAAA/ANAME/CNAME record.
 */
final class ConflictException extends ApiException
{
}
