<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Dashboard\Http;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Sebastka\Domeneshop\Dashboard\Exception\SessionExpiredException;
use Sebastka\Domeneshop\Dashboard\Exception\TransportException;
use Sebastka\Domeneshop\Dashboard\Exception\UnexpectedPageException;

/**
 * Internal HTTP layer for the dashboard.
 *
 * Unlike the API — which authenticates per request with a scoped token — the
 * dashboard authenticates with a browser session cookie. That cookie is the
 * whole of your account: billing, transfers, deletion. This class is therefore
 * the single place that holds it, and it never lets it reach an exception
 * message, a log line or a URL.
 *
 * @internal Resource classes use this; it is not part of the public API.
 */
final class Transport
{
    /**
     * Present only when signed in: the header's sign-out link.
     *
     * Detection deliberately looks for *positive* evidence in both directions
     * rather than inferring one state from the absence of the other. The obvious
     * shortcut — "a login form means signed out" — is wrong here: the dashboard
     * keeps a hidden mobile login form, and its `/login` action, in the markup of
     * every page, including signed-in ones. An earlier version keyed on those and
     * reported every real page as an expired session.
     */
    private const SIGNED_IN_MARKER = 'href="https://domene.shop/logout"';

    /** Present only on the login page itself: the main (desktop) login form. */
    private const LOGIN_PAGE_MARKER = 'name="loginform"';

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly string $cookie,
        private readonly string $baseUri,
        private readonly string $userAgent,
    ) {
    }

    /**
     * Fetch a dashboard page and return its HTML.
     *
     * @param array<string, scalar|null> $query Null values are dropped.
     *
     * @throws SessionExpiredException when the dashboard answers with its login page.
     * @throws UnexpectedPageException when the page shows neither a session nor the login form.
     * @throws TransportException      on a connection failure or a non-2xx status.
     */
    public function get(string $path, array $query = []): string
    {
        $request = $this->requestFactory
            ->createRequest('GET', $this->buildUrl($path, $query))
            ->withHeader('Accept', 'text/html,application/xhtml+xml')
            ->withHeader('User-Agent', $this->userAgent)
            ->withHeader('Cookie', $this->cookie);

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new TransportException(
                \sprintf('HTTP transport error while requesting %s', $path),
                previous: $e,
            );
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new TransportException(\sprintf('Dashboard returned HTTP %d for %s', $status, $path));
        }

        $body = (string) $response->getBody();

        // The dashboard serves its login page with a 200 rather than a 401, so
        // the session has to be read from the body.
        if (str_contains($body, self::SIGNED_IN_MARKER)) {
            return $body;
        }

        if (str_contains($body, self::LOGIN_PAGE_MARKER)) {
            throw SessionExpiredException::forPath($path);
        }

        // Neither signal. Guessing either way would be wrong: calling it an
        // expired session sends the user off to re-capture a cookie that works,
        // and calling it signed in hands a parser a page it cannot read.
        throw UnexpectedPageException::missing('either a sign-out link or the login form', $path);
    }

    /** Whether the session cookie is currently accepted. */
    public function isSignedIn(): bool
    {
        try {
            $this->get('/admin');

            return true;
        } catch (SessionExpiredException) {
            return false;
        }
    }

    /**
     * @param array<string, scalar|null> $query
     */
    private function buildUrl(string $path, array $query): string
    {
        $url = rtrim($this->baseUri, '/') . '/' . ltrim($path, '/');

        $params = array_filter($query, static fn (mixed $v): bool => $v !== null);

        return $params === [] ? $url : $url . '?' . http_build_query($params);
    }
}
