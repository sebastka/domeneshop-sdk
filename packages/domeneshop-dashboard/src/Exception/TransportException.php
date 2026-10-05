<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Dashboard\Exception;

/**
 * The request never produced a usable HTTP response: DNS failure, connection
 * refused, TLS error, or a non-2xx status from the dashboard.
 *
 * The session cookie is never included in the message.
 */
final class TransportException extends \RuntimeException implements DashboardException
{
}
