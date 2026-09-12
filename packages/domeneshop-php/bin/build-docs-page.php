#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Build a self-contained API reference page from the #[OA\*] attributes in src/.
 *
 * Usage:
 *   php bin/build-docs-page.php --renderer=swagger            build/swagger.html
 *   php bin/build-docs-page.php --renderer=rapidoc            build/rapidoc.html
 *   php bin/build-docs-page.php --renderer=swagger --proxy    build/swagger-preview.html
 *   php bin/build-docs-page.php --renderer=rapidoc --proxy    build/rapidoc-preview.html
 *
 * An explicit output path may follow the flags.
 *
 * Two renderers, because they are good at different things: Swagger UI for
 * poking at individual operations, RapiDoc for reading a whole API quickly.
 * Both are driven from one template contract — three placeholders, filled in
 * below — so adding a third renderer means adding a template and one entry to
 * RENDERERS, not another copy of this script.
 *
 * Each renderer builds in two variants, because the API sends no CORS headers:
 *
 *   default   A plain file you can open from disk or hand to someone. Its
 *             try-it console is switched off: every request would be blocked by
 *             the browser, and a button that always fails is worse than none.
 *
 *   --proxy   The preview build, served by bin/docs-router.php. Its `servers`
 *             entry points at the router's same-origin /__proxy prefix, which
 *             forwards to the real API server-side — no CORS involved — so the
 *             try-it console genuinely works.
 *
 * The spec is inlined into the page rather than fetched, so no variant needs a
 * server merely to display itself. Renderer assets load from a CDN, so viewing
 * needs internet; `composer docs:redoc` builds a fully offline page instead.
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

const SPEC_PLACEHOLDER = '/*__OPENAPI_SPEC__*/ null';
const OVERRIDES_PLACEHOLDER = '/*__UI_OVERRIDES__*/ {}';
const BANNER_PLACEHOLDER = '<!--__BANNER__-->';

/** The path prefix bin/docs-router.php forwards to the API. Keep the two in step. */
const PROXY_PREFIX = '/__proxy';

/**
 * The one upstream the docs proxy forwards to. Taken from the client itself so
 * the page, the proxy and the SDK can never disagree about the endpoint.
 */
const DEFAULT_UPSTREAM = \Sebastka\Domeneshop\DomeneshopClient::DEFAULT_BASE_URI;

/**
 * The renderers we know how to build.
 *
 * `overrides` is whatever the template's /*__UI_OVERRIDES__*<!---->/ placeholder
 * expects — Swagger UI takes constructor options, RapiDoc takes element
 * attributes, Redoc takes Redoc.init() options — so each template decides how
 * to apply them.
 *
 * `interactive` says whether the renderer has a try-it console at all. Redoc's
 * open-source build does not (that is Redocly's paid product), so its preview
 * is simply the page served locally: rewriting its `servers` to the proxy would
 * display an endpoint nothing can call, which is worse than useless.
 *
 * @var array<string, array{template: string, basename: string, interactive: bool, overrides: array<string, mixed>}>
 */
$renderers = [
    'swagger' => [
        'template' => 'swagger-ui.html',
        'basename' => 'swagger',
        'interactive' => true,
        'overrides' => [
            'tryItOutEnabled' => true,
            'supportedSubmitMethods' => ['get', 'post', 'put', 'delete'],
            'persistAuthorization' => true,
        ],
    ],
    'rapidoc' => [
        'template' => 'rapidoc.html',
        'basename' => 'rapidoc',
        'interactive' => true,
        'overrides' => [
            'allow-try' => 'true',
            'server-url' => PROXY_PREFIX,
            'allow-server-selection' => 'false',
            'persist-auth' => 'true',
        ],
    ],
    'redoc' => [
        'template' => 'redoc.html',
        'basename' => 'redoc',
        'interactive' => false,
        'overrides' => [],
    ],
];

$proxy = in_array('--proxy', $argv, true);

$renderer = 'swagger';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--renderer=')) {
        $renderer = substr($arg, strlen('--renderer='));
    }
}

if (! isset($renderers[$renderer])) {
    fwrite(STDERR, sprintf(
        "Unknown renderer \"%s\". Available: %s.\n",
        $renderer,
        implode(', ', array_keys($renderers)),
    ));
    exit(1);
}

$config = $renderers[$renderer];

$positional = array_values(array_filter(
    array_slice($argv, 1),
    static fn (string $arg): bool => ! str_starts_with($arg, '--'),
));
$out = $positional[0]
    ?? (__DIR__ . '/../build/' . $config['basename'] . ($proxy ? '-preview' : '') . '.html');

$template = __DIR__ . '/../resources/' . $config['template'];
$html = @file_get_contents($template);
if ($html === false) {
    fwrite(STDERR, sprintf("Could not read the template at %s.\n", $template));
    exit(1);
}

foreach ([SPEC_PLACEHOLDER, OVERRIDES_PLACEHOLDER, BANNER_PLACEHOLDER] as $placeholder) {
    if (! str_contains($html, $placeholder)) {
        fwrite(STDERR, sprintf(
            "The template at %s no longer contains the %s placeholder; refusing to write a broken page.\n",
            $template,
            $placeholder,
        ));
        exit(1);
    }
}

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

/** @var array<string, mixed> $spec */
$spec = json_decode($openapi->toJson(), true, flags: JSON_THROW_ON_ERROR);

$interactive = $config['interactive'];

if ($proxy && $interactive) {
    // Point the spec at the router rather than the API. Requests then leave the
    // browser same-origin, and the router forwards them server-side.
    $spec['servers'] = [[
        'url' => PROXY_PREFIX,
        'description' => 'Local docs proxy, forwarding to ' . DEFAULT_UPSTREAM,
    ]];
}

$overrides = $proxy ? $config['overrides'] : [];

$banner = match (true) {
    $proxy && $interactive => livePreviewBanner(),
    $proxy => servedOnlyBanner(),
    default => staticBanner(),
};

$html = str_replace(
    [SPEC_PLACEHOLDER, OVERRIDES_PLACEHOLDER, BANNER_PLACEHOLDER],
    [inlineJson($spec), inlineJson($overrides, forceObject: true), $banner],
    $html,
);

$dir = dirname($out);
if (! is_dir($dir) && ! mkdir($dir, 0o777, true) && ! is_dir($dir)) {
    fwrite(STDERR, sprintf("Could not create output directory %s.\n", $dir));
    exit(1);
}

file_put_contents($out, $html);

fwrite(STDERR, sprintf(
    "Wrote %s (%s, %d KiB, %s).\n",
    realpath($out) ?: $out,
    $renderer,
    (int) round(strlen($html) / 1024),
    match (true) {
        $proxy && $interactive => 'proxy preview, try-it enabled',
        $proxy => 'served locally, no try-it console in this renderer',
        default => 'static',
    },
));

/**
 * Encode a value for injection into a <script> block.
 *
 * The escaping is the point: a literal "</script>" anywhere in a description
 * would close the block early and leave a blank page that looks perfectly fine
 * on disk. Escaping the slash keeps the JSON equivalent while making that
 * impossible. U+2028/U+2029 are escaped for the same reason — they terminate a
 * line in JavaScript but not in JSON.
 *
 * @param array<mixed> $value
 * @param bool         $forceObject Encode an empty array as `{}` rather than `[]`,
 *                                  so a template can always treat it as an object.
 */
function inlineJson(array $value, bool $forceObject = false): string
{
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
    if ($forceObject && $value === []) {
        $flags |= JSON_FORCE_OBJECT;
    }

    $json = json_encode($value, $flags);

    return str_replace(['</', "\u{2028}", "\u{2029}"], ['<\\/', '\\u2028', '\\u2029'], $json);
}

function staticBanner(): string
{
    return '<div class="ds-banner">'
        . '<strong>Unofficial.</strong> Generated from the '
        . '<a href="https://github.com/sebastka/domeneshop-php">sebastka/domeneshop-php</a> client, '
        . 'not published by Domeneshop.'
        . '&nbsp;·&nbsp;'
        . '<strong>The try-it console is disabled here.</strong> The API sends no CORS headers, so a request '
        . 'from this file would be blocked by the browser. Run <code>composer docs:preview</code> for a '
        . 'version that proxies through a local server and works.'
        . '</div>';
}

/**
 * The preview banner for a renderer with no try-it console. Says plainly that
 * there is nothing to call here, and where to go instead.
 */
function servedOnlyBanner(): string
{
    return '<div class="ds-banner">'
        . '<strong>Unofficial.</strong> Generated from the '
        . '<a href="https://github.com/sebastka/domeneshop-php">sebastka/domeneshop-php</a> client, '
        . 'not published by Domeneshop.'
        . '&nbsp;·&nbsp;'
        . '<strong>Reading only.</strong> Redoc&rsquo;s open-source build has no try-it console — that is '
        . 'Redocly&rsquo;s paid product — so this page is served for convenience, not for calling the API. '
        . 'Use the Swagger UI or RapiDoc preview to make real requests.'
        . '</div>';
}

function livePreviewBanner(): string
{
    return '<div class="ds-banner ds-banner--live">'
        . '<strong>Live preview.</strong> The try-it console is enabled and calls the <strong>real API</strong> '
        . 'through a local proxy — creates and deletes are real. Authorize with your API token as the '
        . 'username and secret as the password.'
        . '&nbsp;·&nbsp;'
        . 'The proxy only forwards to <code>' . htmlspecialchars(DEFAULT_UPSTREAM, ENT_QUOTES) . '</code> '
        . 'and only listens on localhost. Stop it when you are done.'
        . '</div>';
}
