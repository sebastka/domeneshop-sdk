<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Exception;

/**
 * Marker interface implemented by every exception this package throws, so
 * callers can catch all of them with a single `catch (DomeneshopException $e)`.
 */
interface DomeneshopException extends \Throwable
{
}
