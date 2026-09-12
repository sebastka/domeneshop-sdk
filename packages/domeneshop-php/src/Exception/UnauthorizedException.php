<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Exception;

/**
 * HTTP 401 — the API token or secret is missing, wrong, or revoked. Generate a new pair at https://www.domeneshop.no/admin?view=api.
 */
final class UnauthorizedException extends ApiException
{
}
