<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Sebastka\Domeneshop\DomeneshopClient;
use Sebastka\Domeneshop\Resource\Dns;
use Sebastka\Domeneshop\Resource\Domains;
use Sebastka\Domeneshop\Resource\DynDns;
use Sebastka\Domeneshop\Resource\Forwards;
use Sebastka\Domeneshop\Resource\Invoices;

/**
 * @method static Domains  domains()
 * @method static Dns      dns()
 * @method static Forwards forwards()
 * @method static Invoices invoices()
 * @method static DynDns   dynDns()
 *
 * @see DomeneshopClient
 */
final class Domeneshop extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return DomeneshopClient::class;
    }
}
