<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop;

use Http\Discovery\Exception\NotFoundException as DiscoveryNotFoundException;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Sebastka\Domeneshop\Http\Transport;
use Sebastka\Domeneshop\Resource\Dns;
use Sebastka\Domeneshop\Resource\Domains;
use Sebastka\Domeneshop\Resource\DynDns;
use Sebastka\Domeneshop\Resource\Forwards;
use Sebastka\Domeneshop\Resource\Invoices;

/**
 * Entry point for the Domeneshop API.
 *
 *     $client  = new DomeneshopClient('token', 'secret');
 *     $domains = $client->domains()->list();
 *     $client->dns()->create($domains[0]->id, new ARecord('www', '203.0.113.10'));
 *
 * Credentials are generated at https://www.domeneshop.no/admin?view=api.
 *
 * ## HTTP client
 *
 * This package is HTTP-client agnostic: it depends on the PSR-18 and PSR-17
 * interfaces, never on a concrete implementation. Whichever one your project
 * already installs is found automatically via php-http/discovery, so the
 * zero-argument form above works with Guzzle, Symfony's HttpClient, Buzz, and
 * anything else implementing PSR-18.
 *
 * To take control — to reuse a pre-configured client with your own middleware,
 * retries, proxy or logging, or to inject a stub in tests — pass your own:
 *
 *     $client = new DomeneshopClient('token', 'secret', httpClient: $psr18, requestFactory: $psr17, streamFactory: $psr17);
 */
final class DomeneshopClient
{
    public const DEFAULT_BASE_URI = 'https://api.domeneshop.no/v0';

    /** Default overall request timeout, in seconds (0 disables it). */
    public const DEFAULT_TIMEOUT = 30.0;

    /** Default connection timeout, in seconds (0 disables it). */
    public const DEFAULT_CONNECT_TIMEOUT = 10.0;

    public const VERSION = '0.2.0';

    private readonly Transport $transport;

    private ?Domains $domains = null;
    private ?Dns $dns = null;
    private ?Forwards $forwards = null;
    private ?Invoices $invoices = null;
    private ?DynDns $dynDns = null;

    /**
     * @param string $token          The API token (the Basic-auth username).
     * @param string $secret         The API secret (the Basic-auth password).
     * @param float  $timeout        Overall request timeout in seconds; 0 disables it.
     *                               Only applied to a discovered Guzzle client, which is
     *                               the one implementation we can configure generically.
     *                               With any other client — including one you inject —
     *                               set timeouts on the client itself.
     * @param float  $connectTimeout Connection timeout in seconds; same caveat as $timeout.
     *
     * @throws \InvalidArgumentException when the credentials are blank.
     * @throws \RuntimeException         when no PSR-18 client or PSR-17 factory can be found.
     */
    public function __construct(
        string $token,
        string $secret,
        string $baseUri = self::DEFAULT_BASE_URI,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        float $timeout = self::DEFAULT_TIMEOUT,
        float $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
    ) {
        if (trim($token) === '' || trim($secret) === '') {
            throw new \InvalidArgumentException('A Domeneshop API token and secret are both required.');
        }

        $this->transport = new Transport(
            $httpClient ?? self::discoverHttpClient($timeout, $connectTimeout),
            $requestFactory ?? self::discover(
                static fn (): RequestFactoryInterface => Psr17FactoryDiscovery::findRequestFactory(),
                'PSR-17 request factory',
            ),
            $streamFactory ?? self::discover(
                static fn (): StreamFactoryInterface => Psr17FactoryDiscovery::findStreamFactory(),
                'PSR-17 stream factory',
            ),
            $token,
            $secret,
            $baseUri,
            \sprintf('sebastka/domeneshop-php %s (+https://github.com/sebastka/domeneshop-php)', self::VERSION),
        );
    }

    /**
     * Find an installed PSR-18 client.
     *
     * Guzzle gets built directly rather than through discovery so the timeout
     * arguments mean something: PSR-18 has no notion of timeouts, so they can
     * only be applied by constructing the client ourselves. It is also the one
     * client that would otherwise throw on a 4xx before Transport can read the
     * error body, so `http_errors` has to be turned off.
     */
    private static function discoverHttpClient(float $timeout, float $connectTimeout): ClientInterface
    {
        if (class_exists(\GuzzleHttp\Client::class)) {
            return new \GuzzleHttp\Client([
                'timeout' => $timeout,
                'connect_timeout' => $connectTimeout,
                // The API's 4xx bodies carry the reason; let Transport read them
                // rather than having Guzzle throw first.
                'http_errors' => false,
            ]);
        }

        return self::discover(
            static fn (): ClientInterface => Psr18ClientDiscovery::find(),
            'PSR-18 HTTP client',
        );
    }

    /**
     * Run a discovery closure, turning "nothing installed" into an error that
     * says what to do about it.
     *
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
                    . 'or pass your own to the DomeneshopClient constructor.',
                    $what,
                ),
                previous: $e,
            );
        }
    }

    public function domains(): Domains
    {
        return $this->domains ??= new Domains($this->transport);
    }

    public function dns(): Dns
    {
        return $this->dns ??= new Dns($this->transport);
    }

    public function forwards(): Forwards
    {
        return $this->forwards ??= new Forwards($this->transport);
    }

    public function invoices(): Invoices
    {
        return $this->invoices ??= new Invoices($this->transport);
    }

    public function dynDns(): DynDns
    {
        return $this->dynDns ??= new DynDns($this->transport);
    }
}
