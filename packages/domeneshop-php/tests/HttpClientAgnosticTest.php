<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Tests;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as NyholmResponse;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Sebastka\Domeneshop\DomeneshopClient;
use Sebastka\Domeneshop\Tests\Fixture\MockHttpClient;

/**
 * The package depends on the PSR-18/PSR-17 interfaces, never on a concrete
 * implementation. These tests hold that line: the SDK is driven through a
 * stack with no Guzzle in it at all, and the discovery fallback is exercised.
 */
final class HttpClientAgnosticTest extends TestCase
{
    public function testTheSdkWorksOnAStackWithNoGuzzleInIt(): void
    {
        // Nyholm for the PSR-17 factories, a hand-rolled PSR-18 client, and
        // Nyholm's PSR-7 response — not a Guzzle class anywhere in this graph.
        $nyholm = new Psr17Factory();
        $http = new MockHttpClient(new NyholmResponse(
            200,
            [],
            (string) json_encode([['id' => 7, 'domain' => 'example.com']]),
        ));

        $client = new DomeneshopClient('TOKEN', 'SECRET', DomeneshopClient::DEFAULT_BASE_URI, $http, $nyholm, $nyholm);

        $domains = $client->domains()->list();

        self::assertSame(7, $domains[0]->id);
        self::assertSame('example.com', $domains[0]->domain);
        self::assertStringStartsWith('Nyholm\\', $http->lastRequest()::class);
    }

    public function testAPostBodyIsBuiltThroughTheInjectedStreamFactory(): void
    {
        $nyholm = new Psr17Factory();
        $http = new MockHttpClient(new NyholmResponse(201, [], '{"id":55}'));

        $client = new DomeneshopClient('TOKEN', 'SECRET', DomeneshopClient::DEFAULT_BASE_URI, $http, $nyholm, $nyholm);
        $client->dns()->create(1, new \Sebastka\Domeneshop\Model\ARecord('www', '203.0.113.10'));

        self::assertSame(
            ['host' => 'www', 'type' => 'A', 'data' => '203.0.113.10'],
            json_decode((string) $http->lastRequest()->getBody(), true),
        );
    }

    public function testTheConstructorNeedsNothingBeyondCredentials(): void
    {
        // Nothing injected: the client must discover an implementation. This is
        // the path every ordinary consumer takes.
        self::assertInstanceOf(DomeneshopClient::class, new DomeneshopClient('TOKEN', 'SECRET'));
    }

    public function testDiscoveryFindsAnImplementationInThisInstall(): void
    {
        self::assertInstanceOf(ClientInterface::class, Psr18ClientDiscovery::find());
        self::assertInstanceOf(RequestFactoryInterface::class, Psr17FactoryDiscovery::findRequestFactory());
        self::assertInstanceOf(StreamFactoryInterface::class, Psr17FactoryDiscovery::findStreamFactory());
    }

    public function testTheSdkNamesNoConcreteHttpClientInItsPublicApi(): void
    {
        // A constructor signature that mentioned Guzzle would make the package
        // impossible to use without it, whatever composer.json said.
        $constructor = (new \ReflectionClass(DomeneshopClient::class))->getConstructor();
        self::assertNotNull($constructor);

        foreach ($constructor->getParameters() as $parameter) {
            $type = (string) $parameter->getType();
            self::assertStringNotContainsString('GuzzleHttp', $type, "{$parameter->getName()} is tied to Guzzle");
        }
    }

    public function testNoConcreteHttpClientIsAHardDependency(): void
    {
        /** @var array{require: array<string, string>} $composer */
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true);

        foreach (array_keys($composer['require']) as $package) {
            self::assertNotSame('guzzlehttp/guzzle', $package, 'Guzzle must stay optional');
            self::assertNotSame('symfony/http-client', $package, 'No concrete client may be required');
        }
    }

    public function testOnlyPsrInterfacesAreImportedByTheTransport(): void
    {
        // Transport is the one class that touches the wire; it must stay free
        // of any concrete implementation.
        $source = (string) file_get_contents(__DIR__ . '/../src/Http/Transport.php');

        self::assertStringNotContainsString('GuzzleHttp', $source);
        self::assertStringNotContainsString('Nyholm', $source);
    }
}
