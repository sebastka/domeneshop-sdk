<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Dashboard\Tests;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Sebastka\Domeneshop\Dashboard\DashboardClient;
use Sebastka\Domeneshop\Dashboard\Tests\Fixture\MockHttpClient;

abstract class DashboardTestCase extends TestCase
{
    protected const COOKIE = 'sessionid=secret-session-value; other=also-secret';

    protected MockHttpClient $http;

    protected function client(Response ...$responses): DashboardClient
    {
        $this->http = new MockHttpClient(...$responses);

        return new DashboardClient(self::COOKIE, DashboardClient::DEFAULT_BASE_URI, $this->http, new HttpFactory());
    }

    /**
     * A minimal signed-in page. What marks a page as signed in is the sign-out
     * link every dashboard page carries in its account menu, so it is included
     * unless a test asks for a body without it.
     */
    protected static function page(string $body = '<h1>Kontrollpanel</h1>', int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'text/html'], self::signedIn($body));
    }

    /** A response carrying exactly $html, with no sign-out link added. */
    protected static function rawPage(string $html, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'text/html'], $html);
    }

    protected static function signedIn(string $body): string
    {
        return '<html><body><a href="https://domene.shop/logout">Logg ut</a>' . $body . '</body></html>';
    }

    /**
     * A real dashboard page, captured from a live account and redacted: names,
     * addresses, email addresses, domain names and ids, DS digests, invoice
     * codes and CSRF tokens are all replaced with placeholders. Markup is
     * otherwise untouched, apart from inline scripts inside the admin panel.
     */
    protected static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/Fixture/Page/' . $name . '.html');
    }

    /** The DS editor as it looks with no records: the add row alone. */
    protected static function dnssecWithoutRecords(): string
    {
        $html = self::fixture('dnssec');
        $start = strpos($html, '<form method="POST"><tr><td bgcolor="#B9DBFF"');
        self::assertNotFalse($start, 'The fixture should contain a DS record row.');
        $end = strpos($html, '</form>', $start);
        self::assertNotFalse($end);

        return substr_replace($html, '', $start, $end + \strlen('</form>') - $start);
    }

    /** Edit a fixture, failing the test if the edit would silently do nothing. */
    protected static function replaceOnce(string $search, string $replace, string $subject): string
    {
        self::assertSame(1, substr_count($subject, $search), \sprintf('Expected exactly one "%s" in the fixture.', $search));

        return str_replace($search, $replace, $subject);
    }

    /**
     * The real, captured Domeneshop login page — what the dashboard serves when
     * the session is missing or expired. Using the genuine markup means session
     * detection is tested against what the site actually returns, not against
     * an assumption about it.
     */
    protected static function loginPage(int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'text/html'], (string) file_get_contents(__DIR__ . '/Fixture/Page/login.html'));
    }
}
