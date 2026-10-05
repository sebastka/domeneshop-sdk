# domeneshop-dashboard

Read-only access to Domeneshop dashboard data that the API does not expose — contacts, nameservers, glue and DNSSEC.

> **Unofficial, unsupported, and a different kind of package.** This scrapes the Domeneshop web dashboard. Its sibling `sebastka/domeneshop-php` talks to a documented API; this has no contract, no versioning and no guarantees. Read the caveats below before relying on it.

## Why it exists

Contacts, nameservers, glue and DNSSEC have **no API equivalent**. That is established rather than assumed: the monorepo's `NOTES.md` records probing 68 candidate API paths, read-only, and finding none of them routed. Anything beyond the API's fifteen operations is dashboard-only.

## Caveats

- **No contract.** Domeneshop can change the dashboard at any time and nothing warns you. Parsers assert the structure they expect and throw `UnexpectedPageException` rather than returning a plausible-looking empty result — a scraper that quietly said "no nameservers" because the markup moved would be worse than one that crashed.
- **The cookie is your whole account.** An API token is scoped to DNS and forwards. A dashboard session cookie grants billing, transfers and deletion. Store it as carefully as a password.
- **Not sanctioned.** The login form submits a device fingerprint, which suggests automation is not something Domeneshop invites. Prefer the API for anything it can do.
- **Read-only, deliberately.** Nameservers and DNSSEC are the most destructive operations a domain has — a bad NS write takes a domain offline, a bad DS record breaks validation until TTLs expire. Writes stay out until reads have proven stable.

## Status

Reading works: nameservers, DNSSEC, glue and contacts are parsed into typed objects. Every parser is written against a real captured page, kept as a fixture in `tests/Fixture/Page` and redacted, so a dashboard change shows up as a failing test rather than as wrong data.

Writing is not offered, and is not next. See the caveats.

## Install

```bash
composer require sebastka/domeneshop-dashboard guzzlehttp/guzzle
```

Like the API client it is HTTP-client agnostic: it depends on PSR-18/PSR-17 interfaces and discovers whichever implementation your project has.

### With the CLI

Installed alongside `sebastka/domeneshop-cli`, its commands appear in the same `domeneshop` binary under `dashboard:`. The CLI takes no dependency on this package; it discovers it if present.

```bash
composer global require sebastka/domeneshop-cli sebastka/domeneshop-dashboard
domeneshop list    # dashboard:* now present
```

## Session cookie

```bash
domeneshop dashboard:cookie
```

With no cookie configured, this explains how to capture one; with one configured, it checks the dashboard still accepts it.

In short: sign in at `https://domene.shop/login` **in your normal browser**, open developer tools, reload any `/admin` page, and copy that request's whole `Cookie:` header. Store it as:

```bash
export DOMENESHOP_DASHBOARD_COOKIE='...'
```

or in the same config file the API CLI uses, `~/.config/domeneshop/config.ini`:

```ini
dashboard_cookie = "..."
```

Use a normal browser, not a headless one. The login form submits a WebGL device fingerprint including the GPU renderer string; headless browsers report a software renderer, so they look like a different machine.

The cookie is never accepted as a command-line flag, so it stays out of shell history and `ps` — the same rule as the API credentials. It expires, so expect to repeat the capture occasionally; an expired session is reported as `SessionExpiredException`, not as a confusing parse error.

## Commands

| Command | |
| --- | --- |
| `dashboard:cookie` | Check the session cookie, or explain how to capture one |
| `dashboard:nameservers <domain-id>` | The domain's nameservers, and whether DNSSEC is on |
| `dashboard:dnssec <domain-id>` | DNSSEC state, with the DS records where they are yours to see |
| `dashboard:glue <domain-id>` | Glue records |
| `dashboard:contacts <domain-id>` | Contacts (they vary by TLD) and the account's billing contact |
| `dashboard:fetch <domain-id> <page>` | Print a page as raw HTML — `contacts`, `nameservers`, `dnssec`, `glue` |

Every command takes `--json`.

`dashboard:fetch` exists for one job in particular: **capturing parser fixtures.**

```bash
domeneshop dashboard:fetch 1234567 contacts > /tmp/contacts.html
```

A captured page contains personal data and a per-page CSRF token. Redact both before sharing one in a bug report.

## DNSSEC

Where DNSSEC state lives depends on who runs the nameservers, so `dashboard:dnssec` answers both cases in one shape:

```console
$ domeneshop dashboard:dnssec 1234567          # external nameservers
 -------- ----------- ------------- -----------------
  Keytag   Algorithm   Digest type   Digest
 -------- ----------- ------------- -----------------
  2371     13          2             5a5cfe35…
 -------- ----------- ------------- -----------------

$ domeneshop dashboard:dnssec 7654321          # Domeneshop's nameservers
This domain is on Domeneshop's nameservers, so Domeneshop signs the zone and
publishes the DS records itself. The dashboard offers none to read.

  DNSSEC       yes
  DS records   managed by Domeneshop
```

On Domeneshop's own nameservers the DS editor is not served at all — the dashboard quietly returns the domain overview instead — so `records` is **`null`, never `[]`**:

```json
{ "enabled": true, "managed_by_domeneshop": true, "records": null }
```

That distinction is the whole point. Both `.no` domains in the account this was developed against have two DS records published in the parent zone that the dashboard never shows; reporting "no DS records" would be wrong in the direction that breaks validation. Where the editor *is* served, what it lists matched the parent zone exactly on every domain checked.

Use `statusForDomain()` for this; `forDomain()` is the stricter accessor that throws `PageUnavailableException` when there is no editor.

## Library usage

```php
use Sebastka\Domeneshop\Dashboard\DashboardClient;
use Sebastka\Domeneshop\Dashboard\Exception\PageUnavailableException;
use Sebastka\Domeneshop\Dashboard\Exception\SessionExpiredException;
use Sebastka\Domeneshop\Dashboard\Exception\UnexpectedPageException;

$dashboard = new DashboardClient($cookie);

try {
    $ns = $dashboard->nameservers()->forDomain(1234567);
    $ns->hosts;   // ['ns1.hyp.net', 'ns2.hyp.net', 'ns3.hyp.net']
    $ns->dnssec;  // true

    $status = $dashboard->dnssec()->statusForDomain(1234567);
    $status->records;  // list<DsRecord>, or null when Domeneshop manages them

    $dashboard->glue()->forDomain(1234567);      // list<GlueRecord>
    $dashboard->contacts()->forDomain(1234567);  // owner, admin, tech, billing
} catch (SessionExpiredException) {
    // Re-capture the cookie.
} catch (PageUnavailableException) {
    // The dashboard does not offer that page for this domain.
} catch (UnexpectedPageException) {
    // The dashboard changed. Never treat this as "no data".
}
```

`$dashboard->pages()->fetch()` still returns raw HTML, for capturing a page.

### Contacts vary by TLD

Each registry decides which contacts a domain has and what they hold, so every field on `Contact` is nullable and anything unmodelled is kept verbatim in `$contact->extra` rather than dropped. A `.no` domain with an organisation holder has an owner with a handle and organisation number; the same TLD with a private-person holder adds a technical contact and a Norid PID; `.com` and friends have owner, admin and tech with fax fields. See [`NOTES.md`](../../NOTES.md#dashboard-pages-sebastkadomeneshop-dashboard).

## Tests

```bash
composer install
composer test
```

Offline, against a mock PSR-18 client and real captured pages in `tests/Fixture/Page`. Those fixtures are redacted copies of live dashboard pages: names, addresses, email addresses, domain names and ids, DS digests, invoice codes and CSRF tokens are replaced with placeholders, and the markup is otherwise untouched.

Parsing is tested both ways round — that each page yields the right values, and that a changed header, a missing checkbox or an unclassifiable row raises `UnexpectedPageException` instead of quietly returning nothing.

Session detection is tested against the **real** logged-out login page, and against every signed-in fixture: each one also contains a hidden mobile login form, which is exactly what once made detection report a valid session as expired.

A test also asserts the cookie never appears in any exception message, whichever way a request fails.
