# domeneshop-php

A PHP client for the [Domeneshop API](https://api.domeneshop.no/docs/) — domains, DNS records, HTTP forwards, invoices and dynamic DNS.

> **Unofficial.** This is a third-party client; it is not published by Domeneshop.

## Install

```bash
composer require sebastka/domeneshop-php guzzlehttp/guzzle
```

Requires PHP 8.4+. Generate an API token and secret at [domeneshop.no/admin?view=api](https://www.domeneshop.no/admin?view=api).

This package is **HTTP-client agnostic**: it depends on the PSR-18 and PSR-17 *interfaces*, never on a concrete implementation, so it will not drag a second HTTP stack into a project that already has one. You do need *an* implementation — Guzzle above is simply the shortest path, because it provides both the client and the factories in one package. Any of these work just as well:

```bash
composer require sebastka/domeneshop-php guzzlehttp/guzzle       # client + factories
composer require sebastka/domeneshop-php symfony/http-client nyholm/psr7
composer require sebastka/domeneshop-php kriswallsmith/buzz nyholm/psr7
```

Whichever one you install is found automatically. If none is present you get an error that says so, naming the fix — not a confusing failure deeper in the stack.

## Usage

```php
use Sebastka\Domeneshop\DomeneshopClient;
use Sebastka\Domeneshop\Model\ARecord;

$client = new DomeneshopClient('your-token', 'your-secret');

$domain = $client->domains()->findByName('example.com');

$recordId = $client->dns()->create($domain->id, new ARecord(
    host: 'www',
    data: '203.0.113.10',
    ttl: 3600,
));
```

### Resources

| Accessor | Covers |
| --- | --- |
| `domains()` | `list()`, `get()`, `findByName()` |
| `dns()` | `list()`, `get()`, `create()`, `update()`, `delete()` |
| `forwards()` | `list()`, `get()`, `create()`, `update()`, `delete()` |
| `invoices()` | `list()`, `get()` |
| `dynDns()` | `update()` |

Every operation the API documents is implemented.

### DNS records

The API models records as a `oneOf` over seven shapes that share `host` and `ttl` but differ in their payload. Rather than one wide class with mostly-null properties, each shape is its own class, so the fields a record type requires are exactly the fields its constructor demands:

```php
use Sebastka\Domeneshop\Model\{ARecord, AaaaRecord, CnameRecord, MxRecord, SrvRecord, TlsaRecord, TxtRecord};

new ARecord(host: 'www', data: '203.0.113.10');
new AaaaRecord(host: '@', data: '2001:db8::1');
new CnameRecord(host: 'blog', data: 'example.com');
new TxtRecord(host: '@', data: 'v=spf1 include:_spf.domeneshop.no ~all');
new MxRecord(host: '@', data: 'mx.example.com', priority: 10);
new SrvRecord(host: '_sip._tcp', data: 'sip.example.com', priority: 10, weight: 100, port: 5060);
new TlsaRecord(host: '_443._tcp', data: $hash, usage: 3, selector: 1, dtype: 1);
```

Reading them back gives you the same classes, so you can branch on type:

```php
foreach ($client->dns()->list($domain->id) as $record) {
    if ($record instanceof MxRecord) {
        echo "{$record->host} -> {$record->data} (priority {$record->priority})\n";
    }
}
```

`ttl` is validated in the constructor — it must be null (the API default of 3600) or a multiple of 60 between 60 and 604800 — so a bad TTL fails before a request is made.

**Updates are full replacements.** The API's `PUT` replaces the record, so pass every field the record type requires, including the ones you are not changing:

```php
$client->dns()->update($domain->id, $recordId, new MxRecord('@', 'mx2.example.com', priority: 20));
```

### HTTP forwards

A forward's `host` is its identity — the API addresses forwards by host rather than by a numeric id, and rejects an update that changes it with a 412. To move a forward, delete it and create a new one.

```php
use Sebastka\Domeneshop\Model\HttpForward;

$client->forwards()->create($domain->id, new HttpForward(host: '@', url: 'https://www.example.com'));
$client->forwards()->update($domain->id, '@', new HttpForward('@', 'https://new.example.com'));
$client->forwards()->delete($domain->id, '@');
```

Forwards collide with `A`/`AAAA`/`ANAME`/`CNAME` records on the same host; creating one that collides raises a `ConflictException`.

> **⚠️ `update()` and `delete()` cannot currently succeed.** The API's per-host
> forwards endpoint answers `404` for every host, including a forward the
> collection has just listed — confirmed against the production API on two
> domains, with `curl` as well as this client. `create()` and `list()` work, and
> `get()` works because it reads the collection and filters instead. But a
> forward created through the API can, for now, only be changed or removed in
> the Domeneshop web interface. Both methods are kept and send exactly what the
> documentation specifies, so they will start working when the endpoint is
> fixed.

### Dynamic DNS

`dynDns()->update()` sets a hostname's A/AAAA record, creating it if absent. Omit the address to use the public IP the request arrives from — the usual choice when calling from the host itself:

```php
$client->dynDns()->update('home.example.com');
$client->dynDns()->update('home.example.com', '203.0.113.10');
$client->dynDns()->update(['a.example.com', 'b.example.com'], ['203.0.113.10', '2001:db8::1']);
```

### Invoices

```php
use Sebastka\Domeneshop\Model\InvoiceStatus;

foreach ($client->invoices()->list(InvoiceStatus::Unpaid) as $invoice) {
    echo "{$invoice->id}: {$invoice->amount} {$invoice->currency?->value}, due {$invoice->dueDate}\n";
}
```

Only the past three years are available.

## Error handling

Every exception implements `DomeneshopException`, so one `catch` covers the package. Non-2xx responses map to a typed subclass by status code:

| Status | Exception |
| --- | --- |
| 400 | `BadRequestException` |
| 401 | `UnauthorizedException` |
| 403 | `ForbiddenException` |
| 404 | `NotFoundException` |
| 409 | `ConflictException` |
| 412 | `PreconditionFailedException` |
| 429 | `RateLimitException` |
| 5xx | `ServerException` |
| other | `ApiException` |

A request that never produced a response — DNS failure, connection refused, TLS error, unparseable body — raises `TransportException`.

```php
use Sebastka\Domeneshop\Exception\{ConflictException, DomeneshopException};

try {
    $client->forwards()->create($domain->id, $forward);
} catch (ConflictException $e) {
    // $e->help      — the server's explanation, when it sent one
    // $e->errorCode — the API's machine-readable code, when present
    // $e->body      — the raw response body, verbatim
    // $e->getCode() — the HTTP status
} catch (DomeneshopException $e) {
    // anything else from this package
}
```

Credentials never appear in an exception message, and the API's own error text is preserved verbatim.

## Injecting your own HTTP client

Discovery is a convenience, not a constraint. Pass your own PSR-18 client and PSR-17 factories to reuse a pre-configured stack — your own middleware, retries, proxy, logging — or to inject a stub in tests:

```php
$client = new DomeneshopClient(
    'token',
    'secret',
    httpClient: $myPsr18Client,
    requestFactory: $myPsr17Factory,
    streamFactory: $myPsr17Factory,
);
```

**On timeouts.** PSR-18 has no notion of a timeout, so `$timeout` and `$connectTimeout` can only be applied to a client this package constructs itself. That means they take effect only when Guzzle is the discovered implementation — which is also the one client that must be told `http_errors => false`, so the API's error bodies reach the exception rather than being swallowed by Guzzle's own. With any other implementation, and with any client you inject, configure timeouts on the client.

## Unmodelled fields

`Domain` and `Invoice` keep the complete decoded payload in `->raw`, so you can read a field the SDK does not model yet without waiting for a release. Unrecognised enum values (a new domain status, say) decode to `null` rather than throwing, so one upstream addition cannot break reading the rest of the object.

## OpenAPI specification

The published Domeneshop documentation is incomplete and in places wrong, so this package **generates its own spec** from the `#[OA\*]` attributes on the very methods that build the requests. The spec therefore cannot drift from the client.

```bash
composer docs:build         # the spec and all three pages
composer docs:preview       # every preview, served at http://127.0.0.1:8088/

composer openapi            # build/openapi.yaml
composer openapi:json       # build/openapi.json
composer openapi:check      # generate + validate only (the CI gate)

composer swagger            # build/swagger.html      composer swagger-preview
composer rapidoc            # build/rapidoc.html      composer rapidoc-preview
composer redoc              # build/redoc.html        composer redoc-preview
```

Each renderer has a **build** script named after it and a matching **`-preview`** script that builds and serves it. `docs:build` does every build, `docs:preview` does every preview. Nothing else in the script list writes documentation, so there is one obvious command for each thing.

`build/` is a build artifact and is gitignored. swagger-php is a dev-only dependency; the attributes are inert at runtime.

### The three documentation pages

Each is a **single file with the spec baked in**, so you open it straight from disk — no server, no `file://` fetch problem:

| | `swagger.html` | `rapidoc.html` | `redoc.html` |
| --- | --- | --- | --- |
| Best at | poking at individual operations | skimming a whole API quickly | reading end to end |
| Try-it console | ✅ in preview | ✅ in preview | ✗ none exists |

All three build with **PHP alone** and load their assets from a CDN, so viewing needs internet. They render the same generated spec; pick whichever you find easiest to read.

Adding a fourth renderer means adding a template under `resources/` and one entry to `RENDERERS` in [`bin/build-docs-page.php`](bin/build-docs-page.php) — every template fills in the same three placeholders (`__OPENAPI_SPEC__`, `__UI_OVERRIDES__`, `__BANNER__`), so there is one builder rather than one per renderer.

**Redoc has no try-it console.** That is a Redocly paid feature, not an omission here — the string does not appear anywhere in the open-source bundle. So `redoc-preview` serves the page locally like the others, but it is reading only, and its `servers` entry deliberately keeps pointing at the real API: advertising a proxy endpoint that nothing on the page can call would be worse than useless. The page says so in its banner.

For a Redoc page that needs **no internet at all** to view, `composer docs:offline` bundles one with Redocly's CLI into `build/redoc-offline.html`. That is the only script here that needs Node.

None of these can call the API. Domeneshop sends no CORS headers, so a request straight from a `file://` page is blocked by the browser whatever you type into the form — which is why the try-it consoles are switched off in the static builds rather than left there to fail.

### Live preview: `composer docs:preview`

```bash
composer docs:preview     # http://127.0.0.1:8088/
```

This builds a preview variant of each renderer — `swagger-preview.html`, `rapidoc-preview.html` and `redoc-preview.html` — and serves them through [`bin/docs-router.php`](bin/docs-router.php), which lists everything it found at `/`. In a preview the spec's `servers` entry points at `/__proxy` instead of the API, so requests leave the browser **same-origin** and the router makes the real call server-side. CORS never enters into it, and **the Swagger UI and RapiDoc consoles genuinely work** — authorize with your API token as the username and secret as the password. (Redoc's preview is reading only, as above.)

Run `composer swagger-preview`, `composer rapidoc-preview` or `composer redoc-preview` to build one and land straight on it.

The preview files are gitignored: they only mean anything behind the router, and `docs:build` deliberately does not produce them, so a proxy-pointed page can never end up in a release artifact.

> **Calls are real.** The preview talks to the live API. A `POST` creates a record and a `DELETE` removes one. It is a good way to explore the API; it is not a sandbox.

The proxy is a development tool, and deliberately a narrow one:

- It forwards to **exactly one upstream** and nothing else, so it can never act as an open proxy. Point it elsewhere with `DOMENESHOP_BASE_URI` if you need to.
- It **binds to `127.0.0.1`**, so it is not reachable from your network.
- It forwards only `Authorization`, `Content-Type` and `Accept`, dropping everything else.
- It **never logs headers**, and credentials travel in a header rather than the URL, so the server's own request log cannot capture them either.
- It **never follows redirects** — a redirect could leave the allowed upstream and would carry your credentials with it.

Requests containing `..`, or a protocol-relative path that could smuggle in another host, are rejected with a 400 rather than forwarded.

`docs:preview` needs `ext-curl`; the client itself does not.

### Where it differs from the published docs

Both documents describe the same fifteen operations across the same nine paths — the client has full coverage. The differences are in what they get right:

| | Official | Here |
| --- | --- | --- |
| Operations naming an `operationId` | 5 of 15 | **15 of 15** — generators need them |
| `POST .../forwards/` request body | *omitted entirely* | documented as `HTTPForward` |
| `TLSA` required fields | `usage`, `selector`, `dtype` | adds `type` and `data`, without which a record cannot be valid |
| Operations declaring `basicAuth` | 0 | **all 15** |
| `401` responses | undocumented | documented |
| `TLSA` in the record-model reference | absent | present |
| OpenAPI version | 3.0.1 | 3.2.0 |

The generated spec keeps the API's own oddities rather than quietly correcting them, since they are what the wire actually does — `Invoice.type` really is spelled `credit_node`, and the forwards collection path really does need its trailing slash. Both are called out in the spec's descriptions.

The monorepo carries the **full catalogue** in its `NOTES.md`: every defect found in the published definition, every gap between the prose reference and the schema, every API behaviour we deliberately preserve rather than correct, and everything the production API does differently — 30 items, each with how it was established.

## Tests

```bash
composer install
composer test
composer coverage:responses   # is every documented response exercised?
```

The suite drives the client through an injected recording PSR-18 mock, so it needs no network and no credentials.

**Response coverage.** Test names cannot tell you whether every response the OpenAPI document promises is actually exercised, so `composer coverage:responses` measures it: the suite runs with the mock logging every `(method, path, status)` it serves, and the result is compared against the generated spec. Undeclared gaps fail. Today that is **46 of 49** pairs, with the remaining three declared in the script with a reason — they are on `GET /domains/{domainId}/forwards/{host}`, the route the client deliberately never calls because it is broken server-side.

It is a coverage check, not a correctness one: it proves a response shape was driven through the client, not that the assertions about it were good. It also generates the OpenAPI spec and asserts the corrections above, and drives the SDK through a Guzzle-free stack (Nyholm factories plus a hand-rolled PSR-18 client) to keep the package honest about being implementation-agnostic.
