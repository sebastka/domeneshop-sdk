<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Exception;

/**
 * HTTP 429 — too many requests. Back off and retry.
 */
final class RateLimitException extends ApiException
{
}
