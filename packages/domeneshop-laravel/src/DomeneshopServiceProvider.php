<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Laravel;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Sebastka\Domeneshop\DomeneshopClient;

final class DomeneshopServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/domeneshop.php', 'domeneshop');

        // Deferred via provides(), so an app that never touches the API never
        // constructs a client — and never trips the missing-credentials check.
        //
        // Typed against the container contract rather than the foundation's
        // Application: this package requires only illuminate/support, and all
        // the factory needs is config resolution.
        $this->app->singleton(DomeneshopClient::class, static function (Container $app): DomeneshopClient {
            /** @var array{token: string, secret: string, base_uri: string, timeout: int|float|string, connect_timeout: int|float|string} $config */
            $config = $app['config']['domeneshop'];

            return new DomeneshopClient(
                $config['token'],
                $config['secret'],
                $config['base_uri'],
                timeout: (float) $config['timeout'],
                connectTimeout: (float) $config['connect_timeout'],
            );
        });

        $this->app->alias(DomeneshopClient::class, 'domeneshop');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes(
                [__DIR__ . '/../config/domeneshop.php' => $this->app->configPath('domeneshop.php')],
                'domeneshop-config',
            );
        }
    }

    /** @return array<int, string> */
    public function provides(): array
    {
        return [DomeneshopClient::class, 'domeneshop'];
    }
}
