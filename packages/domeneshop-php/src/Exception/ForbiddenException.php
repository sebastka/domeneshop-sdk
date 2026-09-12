<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Exception;

/**
 * HTTP 403 — the credentials are valid but not permitted to touch this resource.
 */
final class ForbiddenException extends ApiException
{
}
