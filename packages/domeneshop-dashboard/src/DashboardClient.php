<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Dashboard;

use Http\Discovery\Exception\NotFoundException as DiscoveryNotFoundException;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Sebastka\Domeneshop\Dashboard\Http\Transport;

/**
 * Read-only access to Domeneshop dashboard functions the API does not expose.
 *
 *     $dashboard = new DashboardClient($cookie);
 *     $contacts  = $dashboard->contacts()->forDomain(1234567);
 *
 * ## Read this before using it
 *
 * This package **scrapes the web dashboard**. That is a different kind of thing
 * from `sebastka/domeneshop-php`, which talks to a documented API, and it comes
 * with different guarantees — really, with none:
 *
 *  - There is no contract and no versioning. Domeneshop can change the markup at
 *    any time and nothing will warn you. Parsers here assert the structure they
 *    expect and throw {@see Exception\UnexpectedPageException} rather than
 *    returning a plausible-looking empty result.
 *  - A session cookie is far more powerful than an API token. The token is
 *    scoped to DNS and forwards; the cookie is your whole account, including
 *    billing, transfers and deletion. Treat it accordingly.
 *  - Automating a dashboard is not something Domeneshop has sanctioned. Prefer
 *    the API for anything the API can do.
 *
 * It exists because contacts, nameservers, glue and DNSSEC have no API equivalent at
 * all — see the monorepo's NOTES.md, which records the endpoint probing behind
 * that conclusion.
 *
 * Like the API client, this is HTTP-client agnostic: it codes against PSR-18
 * and PSR-17 and finds whichever implementation your project already has.
 */
final class DashboardClient
{
    public const DEFAULT_BASE_URI = 'https://domene.shop';

    public const VERSION = '0.3.0';

    private readonly Transport $transport;

    private ?Resource\Pages $pages = null;

    private ?Resource\Nameservers $nameservers = null;

    private ?Resource\Dnssec $dnssec = null;

    private ?Resource\Glue $glue = null;

    private ?Resource\Contacts $contacts = null;

    /**
     * @param string $cookie The dashboard session cookie, as a `Cookie:` header
     *                       value — e.g. `sessionid=...`. Capture one
     *                       with `domeneshop dashboard:cookie`.
     *
     * @throws \InvalidArgumentException when the cookie is blank.
     */
    public function __construct(
        string $cookie,
        string $baseUri = self::DEFAULT_BASE_URI,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
    ) {
        if (trim($cookie) === '') {
            throw new \InvalidArgumentException(
                'A dashboard session cookie is required. Capture one with `domeneshop dashboard:cookie`.',
            );
        }

        $this->transport = new Transport(
            $httpClient ?? self::discover(
                static fn (): ClientInterface => Psr18ClientDiscovery::find(),
                'PSR-18 HTTP client',
            ),
            $requestFactory ?? self::discover(
                static fn (): RequestFactoryInterface => Psr17FactoryDiscovery::findRequestFactory(),
                'PSR-17 request factory',
            ),
            trim($cookie),
            $baseUri,
            \sprintf('sebastka/domeneshop-dashboard %s', self::VERSION),
        );
    }

    /** Whether the session cookie is currently accepted by the dashboard. */
    public function isSignedIn(): bool
    {
        return $this->transport->isSignedIn();
    }

    /** A domain's nameservers and DNSSEC flag. */
    public function nameservers(): Resource\Nameservers
    {
        return $this->nameservers ??= new Resource\Nameservers($this->pages());
    }

    /** A domain's DS records, for domains on external nameservers. */
    public function dnssec(): Resource\Dnssec
    {
        return $this->dnssec ??= new Resource\Dnssec($this->pages());
    }

    /** A domain's glue records. Recognises only the empty case so far; see {@see Parser\GlueParser}. */
    public function glue(): Resource\Glue
    {
        return $this->glue ??= new Resource\Glue($this->pages());
    }

    /** A domain's contacts, which vary by TLD, and the account's billing contact. */
    public function contacts(): Resource\Contacts
    {
        return $this->contacts ??= new Resource\Contacts($this->pages());
    }

    /**
     * The dashboard pages as raw HTML — for capturing fixtures and bug reports.
     *
     * @see Resource\Pages
     */
    public function pages(): Resource\Pages
    {
        return $this->pages ??= new Resource\Pages($this->transport);
    }

    /**
     * @template T of object
     *
     * @param \Closure(): T $discover
     *
     * @return T
     */
    private static function discover(\Closure $discover, string $what): object
    {
        try {
            return $discover();
        } catch (DiscoveryNotFoundException $e) {
            throw new \RuntimeException(
                \sprintf(
                    'No %s found. Install one — for example `composer require guzzlehttp/guzzle` — '
                    . 'or pass your own to the DashboardClient constructor.',
                    $what,
                ),
                previous: $e,
            );
        }
    }
}
