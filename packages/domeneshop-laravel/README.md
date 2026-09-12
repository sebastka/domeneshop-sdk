# domeneshop-laravel

Laravel integration for [`sebastka/domeneshop-php`](https://github.com/sebastka/domeneshop-php) — a service provider, a config file, and a facade.

> **Unofficial.** This is a third-party package; it is not published by Domeneshop.

## Install

```bash
composer require sebastka/domeneshop-laravel
```

The provider and the `Domeneshop` alias are registered automatically through package discovery. Requires PHP 8.4+ and Laravel 11, 12 or 13.

## Configure

Add your credentials to `.env` — generate them at [domeneshop.no/admin?view=api](https://www.domeneshop.no/admin?view=api):

```dotenv
DOMENESHOP_TOKEN=your-token
DOMENESHOP_SECRET=your-secret
```

That is all most applications need. To change timeouts or the endpoint, publish the config file:

```bash
php artisan vendor:publish --tag=domeneshop-config
```

```php
// config/domeneshop.php
return [
    'token'           => env('DOMENESHOP_TOKEN', ''),
    'secret'          => env('DOMENESHOP_SECRET', ''),
    'base_uri'        => env('DOMENESHOP_BASE_URI', DomeneshopClient::DEFAULT_BASE_URI),
    'timeout'         => env('DOMENESHOP_TIMEOUT', 30),
    'connect_timeout' => env('DOMENESHOP_CONNECT_TIMEOUT', 10),
];
```

## Usage

Inject the client wherever you need it — it is bound as a singleton:

```php
use Sebastka\Domeneshop\DomeneshopClient;
use Sebastka\Domeneshop\Model\ARecord;

final class PointSubdomainAtServer
{
    public function __construct(private readonly DomeneshopClient $domeneshop)
    {
    }

    public function handle(string $domain, string $host, string $ip): int
    {
        $target = $this->domeneshop->domains()->findByName($domain)
            ?? throw new \RuntimeException("No such domain: {$domain}");

        return $this->domeneshop->dns()->create($target->id, new ARecord($host, $ip));
    }
}
```

Or use the facade:

```php
use Sebastka\Domeneshop\Laravel\Facades\Domeneshop;

$domains = Domeneshop::domains()->list();
$records = Domeneshop::dns()->list($domains[0]->id);
```

The facade forwards to the same resource accessors the client exposes — `domains()`, `dns()`, `forwards()`, `invoices()` and `dynDns()`. See the [core package's README](https://github.com/sebastka/domeneshop-php) for the full operation surface, the record classes, and error handling.

## Notes

The binding is **deferred**: an application that never touches the API never constructs a client, so missing credentials do not break unrelated requests. The credential check runs when the client is first resolved.

Because the client is a singleton, a test can swap it wholesale:

```php
$this->app->instance(DomeneshopClient::class, $fakeClient);
```

For a fake that records requests without hitting the network, construct a real `DomeneshopClient` with an injected PSR-18 mock — see the core package's test fixtures.
