#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Check that every place declaring a version agrees — and, when given one,
 * that they all equal the version being released.
 *
 * Usage:
 *   php tools/check-versions.php            they must agree with each other
 *   php tools/check-versions.php 0.2.0      ...and equal 0.2.0
 *
 * The version is written in four places across two packages, and nothing else
 * ties them together: a release tag that disagrees with them ships a client
 * that misreports itself in its own User-Agent and OpenAPI document. CI runs
 * this on every push, and the release workflow runs it against the tag.
 */

$root = dirname(__DIR__);

/** @var array<string, array{file: string, pattern: string, kind: string}> $sources */
$sources = [
    'client VERSION' => [
        'file' => 'packages/domeneshop-php/src/DomeneshopClient.php',
        'pattern' => "/public const VERSION = '([^']+)'/",
        'kind' => 'exact',
    ],
    'cli VERSION' => [
        'file' => 'packages/domeneshop-cli/src/Application.php',
        'pattern' => "/public const VERSION = '([^']+)'/",
        'kind' => 'exact',
    ],
    'openapi info.version' => [
        'file' => 'packages/domeneshop-php/src/Doc/OpenApiDefinition.php',
        'pattern' => "/version: '([^']+)'/",
        'kind' => 'exact',
    ],
    'composer branch-alias' => [
        'file' => 'packages/domeneshop-php/composer.json',
        // e.g. "0.1.x-dev" — only the major.minor is meaningful.
        'pattern' => '/"dev-master":\s*"(\d+\.\d+)\.x-dev"/',
        'kind' => 'minor',
    ],
];

$expected = $argv[1] ?? null;
if ($expected !== null && preg_match('/^\d+\.\d+\.\d+$/', $expected) !== 1) {
    fwrite(STDERR, sprintf("Not a version: %s (want MAJOR.MINOR.PATCH, no leading v)\n", $expected));
    exit(2);
}

$found = [];
$failed = false;

foreach ($sources as $label => $source) {
    $path = $root . '/' . $source['file'];
    $contents = @file_get_contents($path);

    if ($contents === false) {
        printf("  %-24s %s\n", $label, 'FILE MISSING: ' . $source['file']);
        $failed = true;
        continue;
    }

    if (preg_match($source['pattern'], $contents, $m) !== 1) {
        printf("  %-24s %s\n", $label, 'NO VERSION FOUND in ' . $source['file']);
        $failed = true;
        continue;
    }

    $found[$label] = ['value' => $m[1], 'kind' => $source['kind']];
    printf("  %-24s %-10s (%s)\n", $label, $m[1], $source['file']);
}

if ($failed) {
    fwrite(STDERR, "\nCould not read every version.\n");
    exit(1);
}

// Everything exact must agree; the branch alias only has to match major.minor.
$exact = array_unique(array_map(
    static fn (array $v): string => $v['value'],
    array_filter($found, static fn (array $v): bool => $v['kind'] === 'exact'),
));

echo "\n";

if (count($exact) !== 1) {
    fwrite(STDERR, sprintf("Versions disagree: %s\n", implode(', ', $exact)));
    exit(1);
}

$version = (string) reset($exact);
$minor = implode('.', array_slice(explode('.', $version), 0, 2));

foreach ($found as $label => $v) {
    if ($v['kind'] === 'minor' && $v['value'] !== $minor) {
        fwrite(STDERR, sprintf(
            "%s is %s.x-dev but the code says %s — the alias must track the same minor.\n",
            $label,
            $v['value'],
            $version,
        ));
        exit(1);
    }
}

if ($expected !== null && $version !== $expected) {
    fwrite(STDERR, sprintf("Declared version is %s, but the release asks for %s.\n", $version, $expected));
    exit(1);
}

printf("All versions agree on %s%s.\n", $version, $expected !== null ? ' (matches the requested tag)' : '');
