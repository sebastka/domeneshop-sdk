<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Exception;

/**
 * HTTP 412 — a precondition failed. Updating an HTTP forward raises this when the payload's `host` differs from the host in the URL; the host is the forward's identity and cannot be changed in place.
 */
final class PreconditionFailedException extends ApiException
{
}
