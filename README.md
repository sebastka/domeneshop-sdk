# Domeneshop SDK

A PHP client, CLI, Laravel bridge, and Terraform/OpenTofu provider for the [Domeneshop API](https://api.domeneshop.no/docs/) — domains, DNS records, HTTP forwards, invoices and dynamic DNS.

> **Unofficial.** This is a third-party project; it is not published by Domeneshop.

> **AI-assisted.** This project — its code, tests, and documentation — was written largely by AI (Claude Code) under human direction and review. Review it before relying on it in production.

## Layout

A monorepo of four independently-installable packages:

| Package | Path | Depends on | Use it from |
| --- | --- | --- | --- |
| `sebastka/domeneshop-php` | [`packages/domeneshop-php`](packages/domeneshop-php) | **PSR-18/17 interfaces only** | Any PHP application |
| `sebastka/domeneshop-cli` | [`packages/domeneshop-cli`](packages/domeneshop-cli) | core + Symfony Console | Ops / humans / scripts |
| `sebastka/domeneshop-laravel` | [`packages/domeneshop-laravel`](packages/domeneshop-laravel) | core + `illuminate/support` | Laravel apps |
| `terraform-provider-domeneshop` | [`packages/terraform-provider-domeneshop`](packages/terraform-provider-domeneshop) | Go, standalone | Terraform / OpenTofu |

The PHP core carries **no framework dependency and no HTTP-client dependency**. It codes against the PSR-18 and PSR-17 interfaces and finds whichever implementation your project already has, so it will not drag a second HTTP stack into an application that has one. The CLI and Laravel packages pin Guzzle, so those stay zero-config.

The Terraform provider is a Go binary and shares no code with the PHP packages — a provider has to ship as a single static binary — but it targets the same API version and mirrors the same models.

## Quick start

```php
// PHP
$client = new Sebastka\Domeneshop\DomeneshopClient('token', 'secret');
$domain = $client->domains()->findByName('example.com');
$client->dns()->create($domain->id, new Sebastka\Domeneshop\Model\ARecord('www', '203.0.113.10'));
```

```bash
# CLI
export DOMENESHOP_TOKEN=... DOMENESHOP_SECRET=...
domeneshop domains:list
domeneshop dns:create 12345 A www 203.0.113.10 --ttl=3600
```

```hcl
# Terraform / OpenTofu
resource "domeneshop_dns_record" "www" {
  domain_id = data.domeneshop_domain.example.id
  host      = "www"
  type      = "A"
  data      = "203.0.113.10"
}
```

Each package documents its own usage in full:

- **[`packages/domeneshop-php`](packages/domeneshop-php)** — library usage, the record classes, injecting your own HTTP client, error handling.
- **[`packages/domeneshop-cli`](packages/domeneshop-cli)** — commands, credential resolution, `--json` scripting conventions.
- **[`packages/domeneshop-laravel`](packages/domeneshop-laravel)** — service provider, config publishing, the facade.
- **[`packages/terraform-provider-domeneshop`](packages/terraform-provider-domeneshop)** — resources, data sources, importing, local development.

## Credentials

Everything here authenticates with an API token and secret, generated at [domeneshop.no/admin?view=api](https://www.domeneshop.no/admin?view=api). The API uses HTTP Basic auth: the token is the username, the secret is the password.

| Consumer | Reads from |
| --- | --- |
| PHP client | constructor arguments |
| CLI | `DOMENESHOP_TOKEN` / `DOMENESHOP_SECRET`, else `~/.config/domeneshop/config.ini` |
| Laravel | `config/domeneshop.php`, i.e. the same env vars |
| Terraform | `DOMENESHOP_TOKEN` / `DOMENESHOP_SECRET`, else the `provider` block |

None of them accept credentials as command-line flags, and no credential is ever included in an exception message or log line.

## API coverage

Every endpoint the API documents is implemented across the PHP packages:

| Area | Endpoints | PHP | CLI | Terraform |
| --- | --- | --- | --- | --- |
| Domains | `GET /domains`, `GET /domains/{id}` | ✅ | ✅ | data source |
| DNS | list / get / create / update / delete | ✅ | ✅ | ✅ resource |
| HTTP forwards | list / get / create, but see note | ✅ | ✅ | ✗ *(disabled)* |
| Invoices | `GET /invoices`, `GET /invoices/{id}` | ✅ | ✅ | — |
| Dynamic DNS | `GET /dyndns/update` | ✅ | ✅ | — |

All seven DNS record types are supported: `A`, `AAAA`, `CNAME`, `MX`, `SRV`, `TLSA`, `TXT` — including `TLSA`, which the official documentation defines as a schema but leaves out of its record-model reference. See [NOTES.md](NOTES.md).

**HTTP forwards are only partly usable, and not through Terraform at all.** The API's per-host forwards endpoint answers `404` for every host, so a forward can be created and listed but never updated or deleted through the API — only in the Domeneshop web interface. The PHP client and CLI still expose the operations (they send exactly what the documentation specifies, so they work the moment it is fixed), but the Terraform resource is **disabled**, because a `destroy` that always fails is worse than no resource. See [NOTES.md item 25](NOTES.md#live-behaviour-the-documentation-gets-wrong).

Domains are read-only everywhere — registering, renewing and transferring are not part of the API.

## OpenAPI specification

The published Domeneshop documentation is incomplete and in places wrong, so we **generate our own** from the `#[OA\*]` attributes on the client's resource methods — the same methods that build the requests, so the spec cannot drift from the code.

```bash
cd packages/domeneshop-php
composer openapi          # build/openapi.yaml
composer openapi:check    # generate + validate (the CI gate)
composer docs:build       # the spec and all three pages, below
```

Three pages are produced, each a single file with the spec baked in, openable straight from disk. All build with PHP alone and load their assets from a CDN:

- **`build/swagger.html`** — Swagger UI. Best for poking at individual operations.
- **`build/rapidoc.html`** — RapiDoc. Best for skimming a whole API.
- **`build/redoc.html`** — Redoc. Best for reading end to end.

Each has a build script named after it (`composer swagger`) and a matching `composer swagger-preview`.

Neither of those can call the API — Domeneshop sends no CORS headers, so a request from a `file://` page is blocked by the browser — which is why "Try it out" is disabled in `swagger.html` rather than left to fail on every request.

For a version where it **does** work:

```bash
cd packages/domeneshop-php
composer docs:preview     # http://127.0.0.1:8088/
```

That serves the renderers through a small local router whose `/__proxy` prefix forwards to the API server-side, so requests are same-origin and CORS never applies. Visit `/` for a list of everything built; authorize with your token and secret and the Swagger UI and RapiDoc consoles work. **Redoc has no try-it console at all** — that is a Redocly paid feature — so its preview is reading only, and the listing labels it as such. **Calls are real** — a `POST` creates a record. The proxy forwards to one upstream only, binds to localhost, forwards no headers beyond `Authorization`/`Content-Type`/`Accept`, follows no redirects, and logs nothing. Details in the [core package's README](packages/domeneshop-php#live-preview-composer-docspreview).

It covers the same fifteen operations as the official document, and fixes what that one gets wrong: every operation gets an `operationId` (upstream names only five), the `POST .../forwards/` request body is documented (upstream omits it entirely), `TLSA` requires its `type` and `data`, and every operation declares `basicAuth`. The full comparison is in [NOTES.md](NOTES.md#what-our-generated-spec-changes).

[`assets/domeneshop-openapi.json`](assets/domeneshop-openapi.json) is the **upstream** definition, extracted from the published documentation and kept for reference and diffing. It is source material, not generated output.

## Development

```bash
composer install                        # from the repo root (path repositories)

# PHP suites
( cd packages/domeneshop-php && composer install && composer test )
( cd packages/domeneshop-cli && composer install && composer test )
( cd packages/domeneshop-laravel && composer install && composer test )

# Terraform provider
( cd packages/terraform-provider-domeneshop && make lint )

# OpenAPI spec generates and validates
( cd packages/domeneshop-php && composer openapi:check )
```

The core suite drives the client through an injected recording PSR-18 mock; the CLI suite drives each command through Symfony's `CommandTester` against that same mock; the Laravel suite boots the provider in a real Illuminate container. The Go suite tests the API client against an `httptest` server and validates every schema through the plugin framework. None of them need network access or credentials.

### Live contract tests

[`packages/domeneshop-php/tests/Contract`](packages/domeneshop-php/tests/Contract) exercises the real API. It is excluded from the default run and gated on credentials:

```bash
cd packages/domeneshop-php

# Read-only checks (lists and lookups). Needs credentials.
DOMENESHOP_TOKEN=... DOMENESHOP_SECRET=... composer test:contract

# Add the mutating tests: they create and delete records on a domain you name.
DOMENESHOP_TOKEN=... DOMENESHOP_SECRET=... \
DOMENESHOP_CONTRACT_WRITE=1 DOMENESHOP_CONTRACT_DOMAIN=example.com \
composer test:contract
```

The mutating tests only ever touch hosts prefixed `sdk-test-`, and clean up after themselves. Point them at a domain you do not mind churning.

## Upstream API and documentation notes

The published Domeneshop documentation is incomplete, and in places the live API contradicts it. Everything found while building this — 30 items, each verified — is catalogued in **[NOTES.md](NOTES.md)**: defects in the published OpenAPI definition, gaps between the prose reference and the schema, behaviours worth knowing, and what the production API actually does.

The one to read before you build anything on forwards:

> **The API's per-host forwards endpoint does not work.** `GET`, `PUT` and `DELETE` on `/domains/{domainId}/forwards/{host}` answer `404` for every host, including one the collection endpoint has just listed. A forward created through the API can currently only be changed or removed in the Domeneshop web interface. See [NOTES.md item 25](NOTES.md#live-behaviour-the-documentation-gets-wrong).

## License

MIT. See [LICENSE](LICENSE).
