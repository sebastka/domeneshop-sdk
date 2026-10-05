<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Dashboard\Exception;

/**
 * Marker interface implemented by every exception this package throws, so
 * callers can catch all of them with a single `catch (DashboardException $e)`.
 */
interface DashboardException extends \Throwable
{
}
