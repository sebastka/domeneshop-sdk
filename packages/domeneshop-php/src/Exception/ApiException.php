<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Exception;

/**
 * Base class for any non-2xx response from the Domeneshop API.
 *
 * The API reuses status codes across endpoints — a 404 may mean "domain not
 * found", "DNS record does not exist" or "forward not found" — so we map the
 * *status code* to a typed subclass (robust) and keep the server's own
 * explanation in {@see self::$help} plus the verbatim body in {@see self::$body}
 * for callers that need to branch further:
 *
 *     try {
 *         $client->dns()->create($domainId, new ARecord(host: '@', data: '203.0.113.10'));
 *     } catch (ConflictException $e) {
 *         // $e->help carries the server's reason, when it sent one.
 *     }
 */
class ApiException extends \RuntimeException implements DomeneshopException
{
    public function __construct(
        public readonly int $statusCode,
        /** The server's human-readable explanation, when the error body carried one. */
        public readonly ?string $help,
        /** Method and path of the failing request, e.g. "POST /domains/1/dns". */
        public readonly string $path,
        /** The raw response body, verbatim. Never contains credentials. */
        public readonly ?string $body = null,
        /**
         * The API's machine-readable error code, when the error body carried one.
         *
         * Named `errorCode` rather than `code` because \Exception already owns
         * `$code` — which we set to the HTTP status, so getCode() is useful too.
         */
        public readonly ?string $errorCode = null,
    ) {
        $reason = $help ?? $errorCode ?? 'Domeneshop API request failed';
        parent::__construct(\sprintf('[%d] %s (%s)', $statusCode, $reason, $path), $statusCode);
    }

    /** Build the most specific exception available for the given status code. */
    public static function fromResponse(
        int $statusCode,
        ?string $help,
        string $path,
        ?string $body = null,
        ?string $errorCode = null,
    ): self {
        $class = match (true) {
            $statusCode === 400 => BadRequestException::class,
            $statusCode === 401 => UnauthorizedException::class,
            $statusCode === 403 => ForbiddenException::class,
            $statusCode === 404 => NotFoundException::class,
            $statusCode === 409 => ConflictException::class,
            $statusCode === 412 => PreconditionFailedException::class,
            $statusCode === 429 => RateLimitException::class,
            $statusCode >= 500 => ServerException::class,
            default => self::class,
        };

        return new $class($statusCode, $help, $path, $body, $errorCode);
    }
}
