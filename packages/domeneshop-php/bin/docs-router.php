<?php

declare(strict_types=1);

/**
 * Router for PHP's built-in server, used by `composer docs:preview`.
 *
 * It does two things:
 *
 *   1. Serves the built documentation out of build/.
 *   2. Forwards /__proxy/... to the Domeneshop API, server-side.
 *
 * The proxy exists for one reason: the API sends no CORS headers, so Swagger
 * UI's "Try it out" cannot call it from a browser at all. Routing the call
 * through this same-origin prefix sidesteps that — the browser is talking to
 * localhost, and this script makes the real request.
 *
 * It is a development tool and is deliberately narrow:
 *
 *   - It forwards to exactly one upstream and nothing else, so it can never be
 *     used as an open proxy.
 *   - `composer docs:preview` binds it to 127.0.0.1, so it is not reachable
 *     from the network.
 *   - It forwards only Authorization, Content-Type and Accept, and logs no
 *     headers, so credentials never reach the terminal or a log file.
 *   - Credentials travel in a header rather than the URL, so the built-in
 *     server's own request log cannot capture them either.
 *
 * Never run it on a public interface, and stop it when you are done.
 */

foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../vendor/autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        break;
    }
}

use Sebastka\Domeneshop\DomeneshopClient;

/** Must match PROXY_PREFIX in bin/build-swagger.php. */
const PROXY_PREFIX = '/__proxy';

/** Headers worth forwarding. Everything else (Host, Cookie, Origin, ...) is dropped. */
const FORWARDED_HEADERS = ['authorization', 'content-type', 'accept'];

const PROXY_TIMEOUT = 30;

$upstream = rtrim(
    (string) (getenv('DOMENESHOP_BASE_URI') ?: DomeneshopClient::DEFAULT_BASE_URI),
    '/',
);

$root = realpath(__DIR__ . '/../build');

// Set DOCS_INDEX to serve one page directly at /; otherwise / lists whatever
// was built, so a single server covers every renderer.
$index = (string) getenv('DOCS_INDEX');

$uri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

if ($uri === PROXY_PREFIX || str_starts_with($uri, PROXY_PREFIX . '/')) {
    proxy($uri, $upstream);
    exit;
}

if ($uri === '/' || $uri === '') {
    serveIndex($root, $index);
    exit;
}

// Anything else: let the built-in server serve it from build/ if it exists.
if ($root !== false && is_file($root . $uri)) {
    return false;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "Not found.\n";

/**
 * Serve `/`: either the page named by DOCS_INDEX, or a listing of what was
 * built. The listing means one server covers every renderer, rather than
 * needing to remember a filename.
 */
function serveIndex(string|false $root, string $index): void
{
    if ($index !== '') {
        $path = $root === false ? false : realpath($root . '/' . $index);

        if ($path === false || ! is_file($path)) {
            http_response_code(503);
            header('Content-Type: text/plain; charset=utf-8');
            printf("%s has not been built yet.\n\nRun:\n    composer docs:preview\n", $index);

            return;
        }

        header('Content-Type: text/html; charset=utf-8');
        readfile($path);

        return;
    }

    // label => [filename, has a working try-it console?]
    //
    // Redoc appears without one on purpose: its open-source build has no
    // console at all, so its preview is the same page, served locally.
    $pages = [
        'Swagger UI' => ['swagger-preview.html', true],
        'RapiDoc' => ['rapidoc-preview.html', true],
        'Redoc' => ['redoc-preview.html', false],
        'Swagger UI (static)' => ['swagger.html', false],
        'RapiDoc (static)' => ['rapidoc.html', false],
        'Redoc (static)' => ['redoc.html', false],
        'Redoc (offline bundle)' => ['redoc-offline.html', false],
    ];

    $rows = '';
    foreach ($pages as $label => [$file, $live]) {
        if ($root === false || ! is_file($root . '/' . $file)) {
            continue;
        }
        $rows .= sprintf(
            '<li><a href="/%s">%s</a>%s</li>',
            rawurlencode($file),
            htmlspecialchars($label, ENT_QUOTES),
            $live ? ' <span class="live">try-it works</span>' : ' <span class="static">reading only</span>',
        );
    }

    foreach (['openapi.yaml', 'openapi.json'] as $spec) {
        if ($root !== false && is_file($root . '/' . $spec)) {
            $rows .= sprintf('<li><a href="/%s">%s</a> <span class="static">raw spec</span></li>', $spec, $spec);
        }
    }

    if ($rows === '') {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Nothing has been built yet.\n\nRun:\n    composer docs:preview\n";

        return;
    }

    header('Content-Type: text/html; charset=utf-8');
    printf(
        '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Domeneshop API docs</title><style>'
        . 'body{font:16px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;'
        . 'margin:0;padding:48px 24px;background:#fafafa;color:#263238}'
        . 'main{max-width:640px;margin:0 auto}h1{font-size:22px;margin:0 0 4px}'
        . 'p.sub{margin:0 0 28px;color:#607d8b;font-size:14px}'
        . 'ul{list-style:none;padding:0;margin:0}'
        . 'li{padding:12px 0;border-bottom:1px solid #e0e0e0}'
        . 'a{color:#00695c;text-decoration:none;font-weight:600}a:hover{text-decoration:underline}'
        . 'span{font-size:12px;font-weight:500;border-radius:3px;padding:2px 7px;margin-left:8px}'
        . '.live{background:#e8f5e9;color:#1b5e20}.static{background:#eceff1;color:#546e7a}'
        . 'footer{margin-top:28px;font-size:13px;color:#607d8b}'
        . '</style></head><body><main>'
        . '<h1>Domeneshop API documentation</h1>'
        . '<p class="sub">Served locally from <code>build/</code>. Unofficial — generated from the '
        . 'sebastka/domeneshop-php client.</p><ul>%s</ul>'
        . '<footer>Pages marked <span class="live">try-it works</span> call the <strong>real API</strong> '
        . 'through this server&rsquo;s <code>%s</code> proxy: creates and deletes are real. '
        . 'Stop the server when you are done.</footer>'
        . '</main></body></html>',
        $rows,
        PROXY_PREFIX,
    );
}

/**
 * Forward one request to the upstream API and relay the response verbatim.
 */
function proxy(string $uri, string $upstream): void
{
    if (! extension_loaded('curl')) {
        fail(500, "The docs proxy needs ext-curl, which is not loaded.\n");

        return;
    }

    $path = substr($uri, strlen(PROXY_PREFIX));
    if ($path === '' || $path === false) {
        $path = '/';
    }

    // The upstream is fixed, but a crafted path must not be able to climb out
    // of it or smuggle in a different host.
    if (str_contains($path, '..') || str_starts_with($path, '//')) {
        fail(400, "Invalid proxy path.\n");

        return;
    }

    $query = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
    $target = $upstream . $path . ($query !== '' ? '?' . $query : '');

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $body = file_get_contents('php://input');

    $headers = [];
    foreach (requestHeaders() as $name => $value) {
        if (in_array($name, FORWARDED_HEADERS, true)) {
            $headers[] = $name . ': ' . $value;
        }
    }

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $target,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_TIMEOUT => PROXY_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 10,
        // Never follow a redirect: it could leave the one upstream we allow,
        // and would carry the Authorization header with it.
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    if ($body !== false && $body !== '' && ! in_array($method, ['GET', 'HEAD'], true)) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }

    $response = curl_exec($curl);

    if ($response === false) {
        // curl_error can name the host, which is fine — it is our own upstream —
        // but it never contains request headers, so no credentials leak here.
        $message = curl_error($curl);
        curl_close($curl);
        fail(502, sprintf("Docs proxy could not reach the API: %s\n", $message));

        return;
    }

    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
    curl_close($curl);

    http_response_code($status);
    header('Content-Type: ' . ($contentType !== '' ? $contentType : 'application/json'));
    // The page and the proxy are same-origin, so no CORS headers are needed —
    // which is the entire point of routing through here.
    echo (string) $response;
}

/**
 * The incoming request's headers, lowercased.
 *
 * Built from $_SERVER rather than getallheaders() so this works the same on
 * every SAPI the built-in server might be running under.
 *
 * @return array<string, string>
 */
function requestHeaders(): array
{
    $headers = [];

    foreach ($_SERVER as $key => $value) {
        if (is_string($key) && str_starts_with($key, 'HTTP_') && is_string($value)) {
            $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
        }
    }

    // These two arrive without the HTTP_ prefix.
    foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $server => $name) {
        if (isset($_SERVER[$server]) && is_string($_SERVER[$server])) {
            $headers[$name] = $_SERVER[$server];
        }
    }

    return $headers;
}

function fail(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
}
