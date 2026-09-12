<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Laravel\Tests;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Sebastka\Domeneshop\DomeneshopClient;
use Sebastka\Domeneshop\Laravel\DomeneshopServiceProvider;
use Sebastka\Domeneshop\Laravel\Facades\Domeneshop;
use Sebastka\Domeneshop\Resource\Dns;
use Sebastka\Domeneshop\Resource\Domains;

/**
 * Drives the provider through a real Illuminate container — the same calls
 * Laravel's own bootstrapper makes — rather than asserting on source text.
 */
final class ServiceProviderTest extends TestCase
{
    private Container $app;
    private DomeneshopServiceProvider $provider;

    protected function setUp(): void
    {
        $this->app = new Container();
        Container::setInstance($this->app);
        $this->app->instance('config', new Repository());
        $this->app->bind('path.config', static fn (): string => '/tmp/config');

        $this->provider = new DomeneshopServiceProvider($this->app);
        $this->provider->register();

        $this->credentials('test-token', 'test-secret');
    }

    protected function tearDown(): void
    {
        Domeneshop::clearResolvedInstances();
        Domeneshop::setFacadeApplication(null);
        Container::setInstance(null);
    }

    private function credentials(string $token, string $secret): void
    {
        $this->app['config']->set('domeneshop.token', $token);
        $this->app['config']->set('domeneshop.secret', $secret);
    }

    public function testRegisterMergesTheDefaultConfig(): void
    {
        self::assertSame(
            DomeneshopClient::DEFAULT_BASE_URI,
            $this->app['config']->get('domeneshop.base_uri'),
        );
        self::assertSame(
            DomeneshopClient::DEFAULT_TIMEOUT,
            $this->app['config']->get('domeneshop.timeout'),
        );
    }

    public function testTheClientResolvesFromTheContainer(): void
    {
        self::assertInstanceOf(DomeneshopClient::class, $this->app->make(DomeneshopClient::class));
    }

    public function testTheClientIsASingleton(): void
    {
        self::assertSame(
            $this->app->make(DomeneshopClient::class),
            $this->app->make(DomeneshopClient::class),
        );
    }

    public function testTheShortAliasResolvesToTheSameInstance(): void
    {
        self::assertSame($this->app->make(DomeneshopClient::class), $this->app->make('domeneshop'));
    }

    public function testTheBindingIsDeferredUntilItIsResolved(): void
    {
        self::assertSame(
            [DomeneshopClient::class, 'domeneshop'],
            $this->provider->provides(),
        );
    }

    public function testEmptyCredentialsFailLoudlyRatherThanBuildingABrokenClient(): void
    {
        $this->credentials('', '');

        $this->expectException(\InvalidArgumentException::class);
        $this->app->make(DomeneshopClient::class);
    }

    public function testAMissingSecretIsRejected(): void
    {
        $this->credentials('a-token', '');

        $this->expectException(\InvalidArgumentException::class);
        $this->app->make(DomeneshopClient::class);
    }

    public function testConfiguredTimeoutsAreAccepted(): void
    {
        // Strings are what env() hands back; the provider casts them.
        $this->app['config']->set('domeneshop.timeout', '5');
        $this->app['config']->set('domeneshop.connect_timeout', '2');

        self::assertInstanceOf(DomeneshopClient::class, $this->app->make(DomeneshopClient::class));
    }

    public function testTheFacadeReachesEveryResource(): void
    {
        Domeneshop::setFacadeApplication($this->app);

        self::assertInstanceOf(Domains::class, Domeneshop::domains());
        self::assertInstanceOf(Dns::class, Domeneshop::dns());
    }

    public function testTheFacadeForwardsToTheBoundClient(): void
    {
        Domeneshop::setFacadeApplication($this->app);

        self::assertSame($this->app->make(DomeneshopClient::class)->dns(), Domeneshop::dns());
    }

    public function testAnApplicationCanSwapTheClientForATestDouble(): void
    {
        $fake = new DomeneshopClient('fake', 'fake');
        $this->app->instance(DomeneshopClient::class, $fake);

        self::assertSame($fake, $this->app->make(DomeneshopClient::class));
    }
}
