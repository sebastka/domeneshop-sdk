#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Check that every response the OpenAPI document promises is exercised by a test.
 *
 * Usage:
 *   php bin/response-coverage.php            report and fail on any undeclared gap
 *   php bin/response-coverage.php --report   report only, always exit 0
 *
 * Test names cannot answer "is every documented response covered?", so this
 * measures it instead: the suite runs with DOMENESHOP_RESPONSE_COVERAGE set,
 * MockHttpClient logs every (method, path, status) it serves, and the result is
 * compared against the generated spec.
 *
 * It is a coverage check, not a correctness one — it proves a response shape was
 * driven through the client, not that the assertions about it were good.
 */

foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../vendor/autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        break;
    }
}

use OpenApi\Annotations\OpenApi as OpenApiDocument;
use OpenApi\Generator;

/**
 * Pairs we knowingly do not cover, with the reason.
 *
 * Keep this list short and justified. A pair belongs here only when the client
 * *cannot* exercise it, never merely because nobody has written the test.
 */
const DECLARED_GAPS = [
    'GET /domains/{domainId}/forwards/{host}' =>
        'The client never calls this route. It is broken server-side — it answers 404 for '
        . 'every host, including one the collection has just listed — so Forwards::get() reads '
        . 'the collection and filters instead. The route stays in the spec because the API does '
        . 'expose it; the spec describes the API, not our workarounds.',
];

$reportOnly = in_array('--report', $argv, true);

// --- what the spec promises ------------------------------------------------

$openapi = (new Generator())->generate([__DIR__ . '/../src'], validate: false);
if ($openapi === null) {
    fwrite(STDERR, "No OpenAPI attributes found under src/.\n");
    exit(1);
}
$openapi->openapi = OpenApiDocument::VERSION_3_2_0;

/** @var array<string, mixed> $spec */
$spec = json_decode($openapi->toJson(), true, flags: JSON_THROW_ON_ERROR);

/** @var array<string, list<string>> $want */
$want = [];
foreach ($spec['paths'] as $path => $item) {
    foreach (['get', 'post', 'put', 'delete'] as $method) {
        if (isset($item[$method])) {
            $want[strtoupper($method) . ' ' . $path] = array_map(strval(...), array_keys($item[$method]['responses']));
        }
    }
}

// --- what the suite exercises ----------------------------------------------

$log = tempnam(sys_get_temp_dir(), 'domeneshop-coverage-');
file_put_contents($log, '');

$phpunit = __DIR__ . '/../vendor/bin/phpunit';
$command = sprintf(
    'DOMENESHOP_RESPONSE_COVERAGE=%s %s %s --no-output 2>&1',
    escapeshellarg($log),
    escapeshellarg(PHP_BINARY),
    escapeshellarg($phpunit),
);
exec($command, $output, $status);

if ($status !== 0) {
    fwrite(STDERR, "The test suite failed, so coverage cannot be measured:\n" . implode("\n", $output) . "\n");
    @unlink($log);
    exit(1);
}

/** @var array<string, array<string, true>> $got */
$got = [];
foreach (file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    [$method, $path, $code] = explode("\t", $line);
    $route = matchRoute($method, $path, array_keys($want));
    if ($route !== null) {
        $got[$route][$code] = true;
    }
}
@unlink($log);

// --- compare ---------------------------------------------------------------

$total = 0;
$covered = 0;
$gaps = [];

foreach ($want as $route => $codes) {
    foreach ($codes as $code) {
        $total++;
        if (isset($got[$route][$code])) {
            $covered++;
        } else {
            $gaps[$route][] = $code;
        }
    }
}

printf("Response coverage: %d/%d documented (operation, response) pairs exercised.\n\n", $covered, $total);

$undeclared = [];
foreach ($want as $route => $codes) {
    $missing = $gaps[$route] ?? [];
    if ($missing === []) {
        printf("  ok    %s\n", $route);
        continue;
    }

    if (isset(DECLARED_GAPS[$route])) {
        printf("  known %s — missing %s\n", $route, implode(', ', $missing));
        continue;
    }

    printf("  GAP   %s — missing %s\n", $route, implode(', ', $missing));
    $undeclared[$route] = $missing;
}

if (DECLARED_GAPS !== []) {
    echo "\nDeclared gaps:\n";
    foreach (DECLARED_GAPS as $route => $reason) {
        printf("  %s\n    %s\n", $route, wordwrap($reason, 76, "\n    "));
    }
}

if ($undeclared === []) {
    echo "\nEvery documented response is exercised, or declared as a gap with a reason.\n";
    exit(0);
}

fwrite(STDERR, sprintf(
    "\n%d operation(s) have undocumented gaps. Add a test, or declare the gap in "
    . "DECLARED_GAPS with a reason it cannot be covered.\n",
    count($undeclared),
));

exit($reportOnly ? 0 : 1);

/**
 * Map a concrete request path back to its templated route.
 *
 * @param list<string> $routes
 */
function matchRoute(string $method, string $path, array $routes): ?string
{
    /** Alphanumeric so preg_quote leaves it untouched. */
    static $PLACEHOLDER = 'ZZROUTEPLACEHOLDERZZ';

    $path = (string) preg_replace('#^/v0#', '', $path);

    foreach ($routes as $route) {
        [$routeMethod, $routePath] = explode(' ', $route, 2);
        if ($routeMethod !== $method) {
            continue;
        }

        // Swap the {placeholders} for a sentinel *before* quoting, so preg_quote
        // cannot escape the braces out from under the replacement. The sentinel
        // has to be alphanumeric: preg_quote turns a NUL byte into a literal
        // \000, which would survive the swap and never match anything.
        $pattern = (string) preg_replace('#\{[^}]+\}#', $PLACEHOLDER, $routePath);
        $pattern = '#^' . str_replace($PLACEHOLDER, '[^/]+', preg_quote($pattern, '#')) . '$#';

        if (preg_match($pattern, $path) === 1) {
            return $route;
        }
    }

    return null;
}
