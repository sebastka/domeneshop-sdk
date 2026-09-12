# domeneshop-cli

A command-line interface for the [Domeneshop API](https://api.domeneshop.no/docs/) — manage domains, DNS records, HTTP forwards, invoices and dynamic DNS from a shell.

> **Unofficial.** This is a third-party tool; it is not published by Domeneshop.

## Install

```bash
composer global require sebastka/domeneshop-cli
```

Make sure Composer's global `bin` directory is on your `PATH` (usually `~/.config/composer/vendor/bin`). Requires PHP 8.4+.

## Credentials

Generate a token and secret at [domeneshop.no/admin?view=api](https://www.domeneshop.no/admin?view=api), then supply them **either** through the environment:

```bash
export DOMENESHOP_TOKEN=your-token
export DOMENESHOP_SECRET=your-secret
```

**or** in `~/.config/domeneshop/config.ini` (`$XDG_CONFIG_HOME` is honoured):

```ini
token  = "your-token"
secret = "your-secret"

; optional
;base_uri        = "https://api.domeneshop.no/v0"
;timeout         = 30
;connect_timeout = 10
```

```bash
chmod 600 ~/.config/domeneshop/config.ini
```

Environment variables win over the file. Credentials are deliberately **not** accepted as command-line flags, so they stay out of your shell history and out of `ps` output.

## Commands

```
domains:list     [--domain=]                       List the domains in the account
domains:get      <domain-id>                       Show one domain

dns:list         <domain-id> [--host=] [--type=]   List a domain's DNS records
dns:get          <domain-id> <record-id>           Show one DNS record
dns:create       <domain-id> <type> <host> <data>  Create a DNS record
dns:update       <domain-id> <record-id> <type> <host> <data>
                                                   Replace a DNS record
dns:delete       <domain-id> <record-id>           Delete a DNS record

forwards:list    <domain-id>                       List a domain's HTTP forwards
forwards:get     <domain-id> <host>                Show one forward
forwards:create  <domain-id> <host> <url> [--frame]
forwards:update  <domain-id> <host> <url> [--frame]
forwards:delete  <domain-id> <host>

invoices:list    [--status=]                       List invoices (past 3 years)
invoices:get     <invoice-id>                      Show one invoice

dyndns:update    <hostname> [--myip=]              Point a hostname at an address
```

Every command accepts `--json`. Run `domeneshop list` to see the catalogue, or `domeneshop help <command>` for the details.

## Examples

```bash
# Find the domain id you need for everything else
domeneshop domains:list
domeneshop domains:list --domain=example

# DNS
domeneshop dns:list 12345
domeneshop dns:list 12345 --type=MX
domeneshop dns:create 12345 A www 203.0.113.10 --ttl=3600
domeneshop dns:create 12345 MX @ mx.example.com --priority=10
domeneshop dns:create 12345 SRV _sip._tcp sip.example.com --priority=10 --weight=100 --port=5060
domeneshop dns:create 12345 TXT @ "v=spf1 include:_spf.domeneshop.no ~all"
domeneshop dns:update 12345 67890 A www 203.0.113.99
domeneshop dns:delete 12345 67890

# HTTP forwards (@ is the zone apex)
domeneshop forwards:create 12345 @ https://www.example.com
domeneshop forwards:delete 12345 @

# Dynamic DNS — omit --myip to use this machine's public IP
domeneshop dyndns:update home.example.com
domeneshop dyndns:update home.example.com --myip=203.0.113.10

# Invoices
domeneshop invoices:list --status=unpaid
```

### DNS record flags

`dns:create` and `dns:update` take the record type as an argument, and its type-specific fields as flags. Missing ones are reported by name, before any request is sent:

| Type | Required flags |
| --- | --- |
| `A`, `AAAA`, `CNAME`, `TXT` | — |
| `MX` | `--priority` |
| `SRV` | `--priority --weight --port` |
| `TLSA` | `--usage --selector --dtype` |

`--ttl` is optional everywhere; omit it for the API's default of 3600. It must be a multiple of 60 between 60 and 604800.

Note that `dns:update` is a **full replacement**, mirroring the API: pass every field the record type requires, including the ones you are not changing.

## Scripting

`--json` prints the raw decoded API payload, so results pipe straight into `jq`:

```bash
# The id of a domain, by name
domeneshop domains:list --domain=example.com --json | jq -r '.[] | select(.domain=="example.com") | .id'

# Every A record's host and address
domeneshop dns:list 12345 --type=A --json | jq -r '.[] | "\(.host) \(.data)"'

# Total outstanding
domeneshop invoices:list --status=unpaid --json | jq '[.[].amount] | add'
```

Errors always go to **stderr** and exit non-zero, so `--json` on stdout stays parseable even on failure:

```bash
if ! records=$(domeneshop dns:list 12345 --json 2>/tmp/err); then
    echo "failed: $(cat /tmp/err)" >&2
    exit 1
fi
```

API errors render as a single line rather than a stack trace.

## Tests

```bash
composer install
composer test
```

The suite drives each command through Symfony's `CommandTester` against a recording mock transport — no network, no credentials.
