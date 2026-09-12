<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Exception;

/**
 * Thrown when the request never produced a usable HTTP response: DNS failure,
 * connection refused, TLS error, or an unparseable response body.
 */
final class TransportException extends \RuntimeException implements DomeneshopException
{
}
