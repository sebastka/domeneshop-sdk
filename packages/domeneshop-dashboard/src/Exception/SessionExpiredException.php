<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Dashboard\Exception;

/**
 * The session cookie is missing, wrong, or no longer valid.
 *
 * Detected by the dashboard answering with its login page instead of the page
 * asked for. That is reported as this, rather than as a parse failure, because
 * "your session expired" and "the page changed shape" call for completely
 * different responses — and an expired session is by far the likelier of the
 * two.
 */
final class SessionExpiredException extends \RuntimeException implements DashboardException
{
    public static function forPath(string $path): self
    {
        return new self(\sprintf(
            'Not signed in: %s returned the login page. The dashboard session cookie is missing or '
            . 'expired — capture a new one with `domeneshop dashboard:cookie`.',
            $path,
        ));
    }
}
