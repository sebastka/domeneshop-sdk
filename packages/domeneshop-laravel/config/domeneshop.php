<?php

declare(strict_types=1);

return [
    // Your Domeneshop API credentials. Keep them in .env, never in source control.
    // Generate a pair at https://www.domeneshop.no/admin?view=api
    'token' => env('DOMENESHOP_TOKEN', ''),
    'secret' => env('DOMENESHOP_SECRET', ''),

    // Override only if you talk to a non-default Domeneshop endpoint.
    'base_uri' => env('DOMENESHOP_BASE_URI', \Sebastka\Domeneshop\DomeneshopClient::DEFAULT_BASE_URI),

    // HTTP timeouts for the default client, in seconds (0 disables).
    'timeout' => env('DOMENESHOP_TIMEOUT', \Sebastka\Domeneshop\DomeneshopClient::DEFAULT_TIMEOUT),
    'connect_timeout' => env('DOMENESHOP_CONNECT_TIMEOUT', \Sebastka\Domeneshop\DomeneshopClient::DEFAULT_CONNECT_TIMEOUT),
];
