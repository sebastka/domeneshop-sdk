# Upstream API and documentation notes

Everything this project has learned about the Domeneshop API that its own documentation does not tell you: defects in the published OpenAPI definition, gaps between the prose reference and the schema, genuine API behaviours worth knowing, and the things the live API does that contradict all of the above.

It is kept out of the [README](README.md) because it is a reference to consult, not something to read end to end — but it is the most useful thing in this repository if you are writing against this API, whether or not you use these packages.

**How to read this.** Items under *Defects in the published definition* and *Prose vs. definition* were verified mechanically by diffing [`assets/domeneshop-openapi.json`](assets/domeneshop-openapi.json) — the upstream document, extracted from the published docs page — against the spec this repo generates. Items under *API behaviours* and *Live behaviour* were **confirmed against the production API** by the contract suite, which has now been run.

## Defects in the published OpenAPI definition

| # | Finding | Why it matters |
| --- | --- | --- |
| 1 | **10 of 15 operations carry no `operationId`.** Only `getDomains`, `getDnsRecords`, `getRecordById`, `getInvoices` and `findInvoiceByNumber` have one. | Client generators fall back to inventing method names from paths, so generated SDKs get unstable, ugly APIs. |
| 2 | **`POST /domains/{domainId}/forwards/` declares no `requestBody` at all.** | The operation reads as if it takes no payload. It does — an `HTTPForward`. Anyone generating a client from this document gets a method that cannot create a forward. |
| 3 | **`TLSA` requires only `usage`, `selector` and `dtype`** — `type` and `data` are missing from its `required` list. | A TLSA record with no type and no certificate hash validates against the published schema, despite being meaningless. |
| 4 | **No operation declares `security`.** `basicAuth` is defined under `components.securitySchemes` and then never applied, and there is no global `security` either. | Every endpoint requires credentials, but the document says none do. Generated clients omit auth entirely. |
| 5 | **Error responses are largely undocumented.** Six of fifteen operations document only `200` — among them `GET /domains/{domainId}` and `GET /domains/{domainId}/dns/{recordId}`, which take an id that may not exist *(that these return 404 is an inference; the contract suite asserts it)*. `POST .../forwards/` omits `404` despite taking a `domainId`. And **no operation documents `401` anywhere**. | The most common failure of all — bad credentials — appears nowhere in the document. |
| 6 | **`myip` uses `format: "ipv4\|ipv6"`.** | Not a valid OpenAPI format. Validators either reject it or silently ignore it. |
| 7 | **The `/invoices` `status` parameter `$ref`s into a schema property**, `#/components/schemas/Invoice/properties/status`, rather than naming an enum. | Legal JSON Pointer, but many tools do not resolve refs that reach inside a schema, and the enum ends up lost. |
| 8 | **A placeholder description shipped to production:** the `SRV` schema is documented, in full, as `"SRV records yo!"`. | — |
| 9 | **`Invoice.status`'s description is truncated mid-sentence:** ``"`settled` is only applicable to credit notes. These are usually created if \ndomains have been\n"``. | The one place that would explain when a credit note appears stops in the middle of the explanation. |
| 10 | **Typo in the `TLSA` `data` description:** "Lenght depends on dtype". | — |
| 11 | The document targets **OpenAPI 3.0.1**, which predates JSON Schema alignment. | Not a defect, but worth knowing: ours targets **3.2.0**. All three renderers we ship accept it — Redoc maps `3.2` onto its 3.1 handling, Swagger UI detects it explicitly, and RapiDoc accepts any `3.x`. |

## Prose reference vs. the definition

| # | Finding | Why it matters |
| --- | --- | --- |
| 12 | **`TLSA` is missing from the record-model reference.** The `dns_record_models` section hand-lists six `<SchemaDefinition>` blocks — `A`, `AAAA`, `CNAME`, `MX`, `SRV`, `TXT` — and omits `TLSA`, even though the schema exists and is part of the `DNSRecord` union. | TLSA is effectively undiscoverable by reading the docs. A reader would reasonably conclude Domeneshop does not support DANE. This is the single most consequential omission, and the reason this repo generates its own spec. |
| 13 | **`ANAME` is referenced but never defined.** The forward-creation description warns that a forward must not collide with an `A`, `AAAA`, `ANAME` or `CNAME` record, but `ANAME` is not a creatable record type and has no schema. | Presumably a Domeneshop-internal alias type surfaced through their DNS editor. You cannot create one through the API, but an existing one can still make a forward collide. |

## API behaviours worth knowing

These are the API working as intended. The client preserves them rather than papering over them, because they are what the wire actually does.

| # | Behaviour | How this project handles it |
| --- | --- | --- |
| 14 | **`Invoice.type` spells "credit note" as `credit_node`.** | Mirrored verbatim on the wire. `InvoiceType::CreditNote` is the PHP-side name, so the typo does not leak into your code. |
| 15 | **The forwards collection path needs its trailing slash** — `/domains/{id}/forwards/`. Without it the API 404s. | Load-bearing, and commented as such in `Forwards.php` and `forwards.go` so nobody "tidies" it away. |
| 16 | **A forward's host is its identity.** Forwards are addressed by host, not by a numeric id, and a `PUT` whose body changes the host is documented as rejected with **412**. *(Unverifiable while item 25 stands: no `PUT` to that endpoint succeeds at all.)* | The SDK documents it; the Terraform provider marks `host` as `RequiresReplace` so a rename becomes destroy-and-create. |
| 17 | **Forwards collide with `A`/`AAAA`/`ANAME`/`CNAME` records on the same host** (409). | Documented in every README. Do not manage both for one host. |
| 18 | **`/dyndns/update` creates records as a side effect** — it is the only endpoint that does. It accepts comma-separated hostnames, and up to **9** comma-separated addresses. *(The creation behaviour is verified; the 9-address limit is documented upstream and enforced client-side rather than probed.)* See also item 30: an update replaces the record. | `DynDns::update()` accepts strings or arrays and rejects more than 9 addresses before sending. |
| 19 | **`PUT` on a DNS record is a full replacement, not a patch.** | Both READMEs say so; the `update()` signature takes a complete record, so it is hard to get wrong. |
| 20 | **`Invoice.amount` is an integer**, in the currency's major unit — not a decimal. | Modelled as `int`. Do not assume minor units. |
| 21 | **Only the last three years of invoices are returned.** | Documented. |
| 22 | **TTL must be a multiple of 60, between 60 and 604800**, defaulting to 3600. | Validated client-side in `DnsRecord`'s constructor and at Terraform plan time, so a bad TTL fails before a request is made. |
| 23 | **Domains are read-only.** Registration, renewal and transfer are not in the API. | The provider exposes domains only as data sources. |
| 24 | **`nameservers` is constrained to 2–6 entries.** | Carried into our schema. |

## Live behaviour the documentation gets wrong

Found by running the contract suite against the production API. Each was reproduced with plain `curl` as well as through the client, so none is an artefact of this SDK.

| # | Finding | Consequence |
| --- | --- | --- |
| 25 | **The per-host forwards endpoint does not work.** `GET`, `PUT` and `DELETE` on `/domains/{domainId}/forwards/{host}` answer `404` for **every** host — including one the collection endpoint has just listed, and one created seconds earlier. Reproduced on two domains, with several path spellings, and it does not resolve with time (retried over 75s, and again after 8 minutes). The route itself **is** registered — `OPTIONS` on it answers `405`, exactly as it does for the working `/dns/{recordId}` route, whereas an unrouted path answers `404`. So this is a broken lookup behind a live route, not a missing endpoint. | **A forward created through the API cannot be changed or removed through the API.** `Forwards::get()` works around it by reading the collection and filtering; `update()` and `delete()` cannot be worked around, because the API exposes no other route. Until this is fixed, a forward made via the API has to be removed in the Domeneshop web interface. The Terraform resource `domeneshop_http_forward` is **disabled for this reason** — a `destroy` that always fails is worse than no resource. Its implementation is kept and schema-tested, ready to re-enable in one line. |
| 26 | **Every domain-scoped operation answers `403`, not the documented `404`,** when the `{domainId}` is not in the account — and does so even for an id that cannot exist (`999999999`). Confirmed on all 11 domain-scoped operations by driving the full response matrix against production. The discriminator is the *domain*, not the sub-resource: a domain you own returns `404` for a missing record or an inactive service, while a domain you do not own returns `403 resource:unauthorized` before the sub-resource is considered at all. | The API never reveals whether a domain exists, only whether it is yours, which stops the endpoint being used to enumerate the register. Sensible, and documented nowhere. **Catch `ForbiddenException`, not `NotFoundException`.** Our spec documents `403` on all 11; the published one documents it on none. |
| 27 | **The forwards collection answers `404` when the domain has no DNS service** (`services.dns === false`), rather than returning an empty list. | Here `404` means "forwarding is not available for this domain", not "no such domain". Check `services.dns` before listing. |
| 28 | **Hostname-valued record data comes back fully qualified, with a trailing dot.** Send `mx.example.com`, read back `mx.example.com.` — the same for `CNAME` and `SRV`. | A naive read-modify-write comparison will always look changed. Compare with the trailing dot normalised, or you will write the record on every run. |
| 29 | **`priority` is returned as a JSON *string*** (`"priority": "42"`), though both the published schema and ours type it as an integer. | The SDK casts it back to `int`, so callers are unaffected. Anything reading the raw payload must not assume the declared type. |
| 30 | **A dynamic-DNS update replaces the record rather than editing it**, so the record **id changes** on every update. | Any id you cached is dangling after the next `dyndns` call — deleting it answers `404`. Re-read the record by host rather than holding its id. |

## What the API does *not* offer

Several things the Domeneshop dashboard can do have no API equivalent at all. Established by probing the live API read-only (`GET` and `OPTIONS` only — an unknown *write* endpoint could have changed nameservers or DNSSEC on a real domain).

The method is worth stating, because a bare `404` is ambiguous here: the API returns the same `{"code":"resource:unknown"}` body for an unrouted path *and* for a missing resource on a real route. `OPTIONS` separates them cleanly — **`405` means the route exists, `404` means it does not** — verified against known-good routes as a positive control and bogus paths as a negative one.

| Dashboard function | Probed as | Result |
| --- | --- | --- |
| Update contact information | `contacts`, `contact`, `registrant`, `owner`, `handles`, `contactinfo`, `contact-info` | no route |
| Change nameservers | `ns`, `nameservers`, `nameserver`, `name-servers`, `delegation` | no route |
| Set up DNSSEC / glue | `dnssec`, `ds`, `dns-sec`, `dnskeys`, `keys`, `glue` | no route |
| Transfer / auth code | `transfer`, `authcode`, `epp` | no route |
| Renewal | `renew`, `autorenew` | no route |
| Account, billing, products | `/v0/account`, `/v0/me`, `/v0/users`, `/v0/orders`, `/v0/products`, `/v0/services`, `/v0/billing`, `/v0/subscriptions`, `/v0/payment`, `/v0/settings` | no route |

**68 unique paths** were probed; not one exists. There is also no `v1` — `/v1/*` and `/` both redirect to the documentation — and no machine-readable spec served from the API host.

The conclusion is that `api.domeneshop.no/v0` implements **exactly** the fifteen operations its documentation describes, with nothing hidden. Anything the dashboard can do beyond those fifteen is dashboard-only, and reaching it would mean driving the web interface with a session cookie rather than the API.

## What our generated spec changes

Our spec describes the **same fifteen operations across the same nine paths** — coverage is identical, so nothing upstream documents was dropped. The differences are corrections:

| | Official | Here |
| --- | --- | --- |
| Operations naming an `operationId` | 5 of 15 | **15 of 15** |
| `POST .../forwards/` request body | omitted entirely | documented as `HTTPForward` |
| `TLSA` required fields | `usage`, `selector`, `dtype` | adds `type` and `data` |
| Operations declaring `basicAuth` | 0 | **all 15** |
| `401` documented | on **0** of 15 operations | on **all 15**, via one shared `components/responses` entry |
| `404` on the 12 operations taking a path id | missing on **5** of 12 | on **all 12** |
| `403` on the 11 domain-scoped operations | on **0** — it documents `404`, which is wrong | on **all 11**, via one shared `components/responses` entry |
| `TLSA` in the record-model reference | absent | present |
| OpenAPI version | 3.0.1 | **3.2.0** |

Items 14–30 are deliberately **not** "fixed" — a spec that disagrees with the server is worse than one that documents an oddity. They are called out in the generated descriptions instead.

Regenerate and compare at any time:

```bash
cd packages/domeneshop-php
composer openapi:json
diff <(jq -S . ../../assets/domeneshop-openapi.json) <(jq -S . build/openapi.json)
```

## Reporting something new

If you find another discrepancy, add it to the relevant table in this file rather than quietly working around it in code — a workaround with no explanation is indistinguishable from a bug.

Two conventions worth keeping:

- Say how it was established. A mechanical diff against the published document and a call against the production API are different kinds of evidence, and the tables distinguish them.
- If the live API turns out to disagree with an entry here, that is the more valuable finding. Correct the entry and say so, rather than deleting it.
