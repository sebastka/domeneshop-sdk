<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Exception;

/**
 * HTTP 400 — the request failed validation. For DNS writes this usually means a malformed `data` value for the record type, or a TTL outside 60–604800.
 */
final class BadRequestException extends ApiException
{
}
