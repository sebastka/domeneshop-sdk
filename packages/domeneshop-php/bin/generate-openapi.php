#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Generate the OpenAPI document from the #[OA\*] attributes in src/.
 *
 * Usage:
 *   php bin/generate-openapi.php [output-path]   Write the spec (default: build/openapi.yaml)
 *   php bin/generate-openapi.php --json          Write JSON instead of YAML
 *   php bin/generate-openapi.php --check         Generate + validate only; no file written (CI)
 *
 * The attributes across src/ — the resource methods plus the holders in
 * src/Doc — are the single source of truth, so the spec cannot drift from the
 * client. swagger-php is a dev-only dependency and the attributes are inert at
 * runtime. The generated spec is a build artifact; build/ is gitignored.
 */

foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../vendor/autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        break;
    }
}

use OpenApi\Annotations\OpenApi as OpenApiDocument;
use OpenApi\Generator;

if (! class_exists(Generator::class)) {
    fwrite(STDERR, "zircote/swagger-php is not installed. Run `composer install` in packages/domeneshop-php.\n");
    exit(1);
}

$check = in_array('--check', $argv, true);
$asJson = in_array('--json', $argv, true);

$positional = array_values(array_filter(
    array_slice($argv, 1),
    static fn (string $arg): bool => ! str_starts_with($arg, '--'),
));
$out = $positional[0] ?? (__DIR__ . '/../build/openapi.' . ($asJson ? 'json' : 'yaml'));

// validate: false here, then validate() below, so we can report failures ourselves.
$openapi = (new Generator())->generate([__DIR__ . '/../src'], validate: false);

if ($openapi === null) {
    fwrite(STDERR, "No OpenAPI attributes found under src/.\n");
    exit(1);
}

// Pinned to the constant rather than a literal, so an unsupported version
// fails at generation instead of producing a document nothing will read.
// 3.2 is a strict superset of 3.1; Redoc, Swagger UI and RapiDoc all accept it.
$openapi->openapi = OpenApiDocument::VERSION_3_2_0;

if (! $openapi->validate()) {
    fwrite(STDERR, "OpenAPI spec failed validation (see messages above).\n");
    exit(1);
}

// A spec that validates but describes nothing would pass silently, so assert
// that every operation we expect actually made it in.
$expected = [
    'listDomains', 'getDomain',
    'listDnsRecords', 'getDnsRecord', 'createDnsRecord', 'replaceDnsRecord', 'deleteDnsRecord',
    'listForwards', 'getForward', 'createForward', 'replaceForward', 'deleteForward',
    'listInvoices', 'getInvoice',
    'updateDynDns',
];

$found = [];
foreach ((array) $openapi->paths as $path) {
    foreach (['get', 'post', 'put', 'delete', 'patch'] as $method) {
        $operation = $path->{$method} ?? null;
        if ($operation !== null && $operation !== Generator::UNDEFINED && is_string($operation->operationId)) {
            $found[] = $operation->operationId;
        }
    }
}

$missing = array_diff($expected, $found);
if ($missing !== []) {
    fwrite(STDERR, sprintf("Operations missing from the generated spec: %s\n", implode(', ', $missing)));
    exit(1);
}

$summary = sprintf(
    "OpenAPI %s: %d paths, %d operations, %d schemas.\n",
    $openapi->openapi,
    count((array) $openapi->paths),
    count($found),
    count((array) ($openapi->components->schemas ?? [])),
);

if ($check) {
    fwrite(STDERR, $summary);
    fwrite(STDERR, "Spec generates and validates.\n");
    exit(0);
}

$dir = dirname($out);
if (! is_dir($dir) && ! mkdir($dir, 0o777, true) && ! is_dir($dir)) {
    fwrite(STDERR, sprintf("Could not create output directory %s.\n", $dir));
    exit(1);
}

file_put_contents($out, $asJson ? $openapi->toJson() : $openapi->toYaml());
fwrite(STDERR, $summary);
fwrite(STDERR, sprintf("Wrote %s\n", realpath($out) ?: $out));
