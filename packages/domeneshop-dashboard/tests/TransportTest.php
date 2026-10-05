<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Dashboard\Tests;

use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Sebastka\Domeneshop\Dashboard\DashboardClient;
use Sebastka\Domeneshop\Dashboard\Exception\SessionExpiredException;
use Sebastka\Domeneshop\Dashboard\Exception\TransportException;
use Sebastka\Domeneshop\Dashboard\Exception\UnexpectedPageException;

final class TransportTest extends DashboardTestCase
{
    public function testTheSessionCookieIsSentOnEveryRequest(): void
    {
        $client = $this->client(self::page());
        $client->pages()->fetch(1, 'contacts');

        self::assertSame(self::COOKIE, $this->http->lastHeader('Cookie'));
    }

    public function testTheRequestIdentifiesThePackage(): void
    {
        $client = $this->client(self::page());
        $client->pages()->fetch(1, 'contacts');

        self::assertStringContainsString('domeneshop-dashboard', $this->http->lastHeader('User-Agent'));
    }

    /**
     * The dashboard answers an unauthenticated request with a 200 and its login
     * page, not a 401 — so expiry has to be read from the body.
     */
    public function testTheRealLoginPageIsRecognisedAsAnExpiredSession(): void
    {
        $client = $this->client(self::loginPage(200));

        $this->expectException(SessionExpiredException::class);
        $client->pages()->fetch(1, 'contacts');
    }

    /**
     * Every signed-in page also contains a (hidden) mobile login form posting
     * to /login, so "has a login form" is not evidence of being signed out.
     * Detection once got this wrong and reported every real page as expired.
     *
     * @return iterable<string, array{string}>
     */
    public static function signedInFixtureProvider(): iterable
    {
        foreach (['nameservers', 'nameservers-external', 'dnssec', 'dnssec-unavailable', 'glue', 'contacts-no', 'contacts-no-tech', 'contacts-generic'] as $name) {
            yield $name => [$name];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('signedInFixtureProvider')]
    public function testARealSignedInPageIsNotMistakenForTheLoginPage(string $fixture): void
    {
        $html = self::fixture($fixture);
        self::assertStringContainsString('mobileloginform', $html, 'The fixture should carry the mobile login form that once caused this.');

        self::assertSame($html, $this->client(self::rawPage($html))->pages()->fetch(1, 'contacts'));
    }

    /**
     * A 200 that is neither a dashboard page nor the login page — a maintenance
     * notice, a consent wall, a changed layout — must not pass as signed in.
     */
    public function testAPageWithNeitherMarkerIsUnexpected(): void
    {
        $client = $this->client(self::rawPage('<html><body><h1>Vedlikehold</h1></body></html>'));

        $this->expectException(UnexpectedPageException::class);
        $client->pages()->fetch(1, 'contacts');
    }

    public function testExpiryIsReportedAsExpiryNotAsAParseFailure(): void
    {
        $client = $this->client(self::loginPage());

        try {
            $client->pages()->fetch(1, 'contacts');
            self::fail('Expected a SessionExpiredException.');
        } catch (SessionExpiredException $e) {
            self::assertStringContainsString('dashboard:cookie', $e->getMessage());
        }
    }

    public function testIsSignedInIsTrueForAnOrdinaryPage(): void
    {
        self::assertTrue($this->client(self::page())->isSignedIn());
    }

    public function testIsSignedInIsFalseForTheLoginPage(): void
    {
        self::assertFalse($this->client(self::loginPage())->isSignedIn());
    }

    public function testANonSuccessStatusIsATransportError(): void
    {
        $client = $this->client(self::page('<html>boom</html>', 500));

        $this->expectException(TransportException::class);
        $client->pages()->fetch(1, 'contacts');
    }

    public function testAConnectionFailureIsATransportError(): void
    {
        $failing = new class () implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class ('connection refused') extends \RuntimeException implements ClientExceptionInterface {
                };
            }
        };

        $client = new DashboardClient(self::COOKIE, DashboardClient::DEFAULT_BASE_URI, $failing, new HttpFactory());

        $this->expectException(TransportException::class);
        $client->pages()->fetch(1, 'contacts');
    }

    /**
     * The cookie is the whole account. It must never reach an exception message,
     * whichever way a request fails.
     */
    public function testTheCookieNeverAppearsInAnyErrorMessage(): void
    {
        $messages = [];

        foreach ([self::loginPage(), self::page('<html>boom</html>', 500)] as $response) {
            try {
                $this->client($response)->pages()->fetch(1, 'contacts');
            } catch (\Throwable $e) {
                $messages[] = $e->getMessage();
            }
        }

        self::assertCount(2, $messages, 'Both failure paths should have thrown.');

        foreach ($messages as $message) {
            self::assertStringNotContainsString('secret-session-value', $message);
            self::assertStringNotContainsString('also-secret', $message);
        }
    }

    public function testABlankCookieIsRejectedUpFront(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DashboardClient('   ');
    }
}
