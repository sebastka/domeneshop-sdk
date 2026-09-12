# Contributing

Thanks for taking a look. This is a monorepo of four packages that are published separately; develop here, and CI mirrors each `packages/*` directory into its own repository.

## Layout

| Path | Language | Published as |
| --- | --- | --- |
| `packages/domeneshop-php` | PHP 8.4+ | `sebastka/domeneshop-php` (Packagist) |
| `packages/domeneshop-cli` | PHP 8.4+ | `sebastka/domeneshop-cli` (Packagist) |
| `packages/domeneshop-laravel` | PHP 8.4+ | `sebastka/domeneshop-laravel` (Packagist) |
| `packages/terraform-provider-domeneshop` | Go 1.23+ | `sebastka/domeneshop` (Terraform Registry) |

`domeneshop-cli` and `domeneshop-laravel` both depend on `domeneshop-php` through a Composer **path repository**, so a change to the core is picked up by the other two without a release. The Terraform provider shares no code — a provider has to ship as one static binary — so its Go client in `internal/client` mirrors the PHP one by hand. **If you change the shape of an API call, change it in both.**

## Getting set up

```bash
composer install                                             # repo root

( cd packages/domeneshop-php && composer install )
( cd packages/domeneshop-cli && composer install )
( cd packages/domeneshop-laravel && composer install )
```

## Running the tests

```bash
( cd packages/domeneshop-php && composer test )
( cd packages/domeneshop-cli && composer test )
( cd packages/domeneshop-laravel && composer test )
( cd packages/terraform-provider-domeneshop && make lint )
```

None of these need network access or credentials:

- The **PHP** suite injects a recording PSR-18 mock into a real `DomeneshopClient`, so assertions cover the client and the exact request it would have sent.
- The **CLI** suite drives each command through Symfony's `CommandTester` against that same mock, so it covers argument parsing, rendering and error handling end to end.
- The **Laravel** suite registers the service provider in a real Illuminate container and resolves through it, so the binding, the deferral, the config merge and the facade are all exercised rather than assumed.
- The **Go** suite runs the API client against an `httptest` server, and validates every resource and data-source schema through the plugin framework's `ValidateImplementation`.

### Response coverage

```bash
cd packages/domeneshop-php && composer coverage:responses
```

Every response the OpenAPI document promises should be exercised somewhere. This measures it rather than assuming: `MockHttpClient` logs each `(method, path, status)` it serves when `DOMENESHOP_RESPONSE_COVERAGE` is set, and [`bin/response-coverage.php`](packages/domeneshop-php/bin/response-coverage.php) compares that against the generated spec. CI runs it, and an undeclared gap fails the build.

Add a documented response to the spec and this will fail until a test drives it — usually one line in `tests/ResponseCoverageTest.php`, which is table-driven over the operations. If a pair genuinely *cannot* be covered, add it to `DECLARED_GAPS` with the reason. Keep that list short: it is for responses the client cannot reach, never for ones nobody has got around to testing.

### Live contract tests

`packages/domeneshop-php/tests/Contract` talks to the real API. It is excluded from the default run and gated twice: read-only tests need credentials, and mutating tests additionally need an explicit opt-in and a named domain.

```bash
cd packages/domeneshop-php

# Read-only. Safe against a production account.
DOMENESHOP_TOKEN=... DOMENESHOP_SECRET=... composer test:contract

# Mutating. Creates and deletes records on the domain you name.
DOMENESHOP_TOKEN=... DOMENESHOP_SECRET=... \
DOMENESHOP_CONTRACT_WRITE=1 DOMENESHOP_CONTRACT_DOMAIN=example.com \
composer test:contract
```

Every host the mutating tests create carries an `sdk-test-` prefix and a random suffix, and each test cleans up in a `finally` block so a mid-test failure still removes what it made. Point them at a domain you do not mind churning.

`LiveResponseMatrixTest` drives **every documented response** against the real API, which is a different question from the offline coverage check: that one proves the client maps a status correctly, this one proves the API actually produces it. It is what caught the `403`/`404` discrepancy in NOTES item 26.

Six response pairs are written but **commented out** in that file — everything that needs an HTTP forward to exist. A forward created through the API cannot be removed through it, so running them would strand one on a real domain. Uncomment the block when the endpoint is fixed.

Run the contract suite when you change how a request is built or a response is decoded — the mock suite will happily agree with a wrong assumption.

### Trying the Terraform provider locally

```bash
cd packages/terraform-provider-domeneshop
make build
```

Then point Terraform at the binary with a `dev_overrides` block in `~/.terraformrc`:

```hcl
provider_installation {
  dev_overrides {
    "sebastka/domeneshop" = "/absolute/path/to/packages/terraform-provider-domeneshop"
  }
  direct {}
}
```

With an override in place, skip `terraform init` and run `plan`/`apply` directly. Both Terraform and OpenTofu work.

## Dotfiles are ignored by default

`.gitignore` starts with `.*` and then allows specific dotfiles back in:

```gitignore
.*
!.gitignore
!.github/
!.php-cs-fixer.dist.php
!.golangci.yml
!.goreleaser.yml
```

The default-deny is deliberate: a stray `.env`, `.idea/`, `.vscode/`, editor swap file or tool cache is easy to commit by accident and occasionally embarrassing. It also means the rest of the file stays short — `.terraform/`, `.phpunit.result.cache` and friends need no rules of their own.

**The trap:** adding a dotfile that *should* be committed, and finding `git add .` silently skips it. Add a `!` line when you do, and use `git check-ignore -v <path>` to see which rule is responsible. Note the trailing slash on directories — git will not descend into an ignored directory, so `!.github/` has to un-ignore the directory itself before anything inside it is considered.

CI checks that the dotfiles which must ship are still visible to git, so breaking one of those `!` lines fails the build rather than quietly dropping a workflow.

## Code style

```bash
composer style       # check (the CI gate); writes nothing
composer style:fix   # apply
```

PHP-CS-Fixer runs `@PSR12` plus one rule that is there for a concrete reason: **`native_function_invocation`**. Inside a namespace, an unqualified `sprintf()` makes PHP look for `Sebastka\Domeneshop\sprintf()` first and only then fall back to the global one, which stops the compiler substituting its specialised handling. Prefixing `\` restores it — measurably, around 2.6x on a tight `sprintf` loop.

That difference is irrelevant to this library's actual work (an API call spends ~0.001% of its time there; the network dominates), so treat it as a consistency rule that happens to be free, not as a performance argument. It is enforced because it is the kind of thing editors warn about, and a codebase that is half-prefixed is worse than one that picks either.

Only the `@compiler_optimized` set is prefixed — the functions that actually benefit. Do not prefix every global call, and note that **class** references are deliberately left inline (`\RuntimeException`), not rewritten into `use` statements.

## Conventions

- **PHP**: `declare(strict_types=1)` everywhere, constructor property promotion, readonly properties on DTOs. Resource methods take and return models, never raw arrays — `->raw` is there for fields the SDK does not model yet.
- **Go**: `gofmt` and `go vet` are CI gates. Optional API fields are pointers so `omitempty` can leave them out; sending `priority: 0` on an `A` record is rejected by the API.
- **Comments** explain *why*, not *what*. The API has enough surprises — a trailing slash that is load-bearing, a `credit_node` typo, a host that is an identity — that the reasons are worth writing down.
- **Credentials never appear** in an exception message, a log line, a URL, or a CLI flag. If you add a code path that could leak them, add a test that proves it does not.

## Adding an API operation

1. Add the method to the right `Resource` class in `packages/domeneshop-php/src/Resource`, with a model in `src/Model` if it returns something new.
2. Add a unit test in `tests/Resource` asserting the method, path, query and body.
3. Add a contract test if the response shape is not obvious from the docs.
4. Add a CLI command to the registry in `packages/domeneshop-cli/src/Commands.php` — the catalogue test will fail until you do, which is the point.
5. If Terraform should manage it, mirror the call in `internal/client` and add the resource or data source.
6. Add the `#[OA\*]` attributes to the new method, and reusable pieces to `src/Doc` — `composer openapi:check` fails until every expected operation is in the spec, which is the point.
7. Update the affected READMEs.

## The OpenAPI spec

We publish our own spec rather than vendoring Domeneshop's, because theirs is incomplete and in places wrong. It is generated from the `#[OA\*]` attributes on the resource methods plus the holders in `packages/domeneshop-php/src/Doc`, so it cannot drift from the client.

```bash
cd packages/domeneshop-php
composer openapi:check    # generate + validate; writes nothing
composer openapi          # write build/openapi.yaml
composer docs:build       # the spec plus swagger.html, rapidoc.html and redoc.html
```

Renderers share one builder, [`bin/build-docs-page.php`](packages/domeneshop-php/bin/build-docs-page.php): each template fills in the same three placeholders (`__OPENAPI_SPEC__`, `__UI_OVERRIDES__`, `__BANNER__`), so adding one means adding a template and a `RENDERERS` entry, not another copy of the script. `__UI_OVERRIDES__` is whatever the template expects — Swagger UI takes constructor options, RapiDoc takes element attributes — so the template decides how to apply them.

Script naming is deliberate: each renderer has a build script named after it (`composer swagger`) and a matching `composer swagger-preview`; `docs:build` and `docs:preview` are the do-everything umbrellas; `openapi*` covers the spec. Keep that shape when adding one.

A renderer's `RENDERERS` entry carries an `interactive` flag. Redoc's is `false` because its open-source build has no try-it console, so its preview keeps the real `servers` URL instead of pointing at the proxy — showing an endpoint nothing can call would be worse than useless. If you add a renderer, set the flag honestly rather than assuming a console exists.

`composer docs:preview` builds a proxy-backed variant of every renderer and serves them at `http://127.0.0.1:8088/`, where the Swagger UI and RapiDoc consoles work against the live API. It works by pointing the spec's `servers` entry at `/__proxy` so requests are same-origin, with [`bin/docs-router.php`](packages/domeneshop-php/bin/docs-router.php) making the real call server-side — the API sends no CORS headers, so this is the only way to drive it from a browser. The prefix is a `PROXY_PREFIX` constant in **both** the builder and the router; a test asserts they agree, because if they drift "Try it out" 404s with no clue why.

Treat the proxy as what it is: a local tool that forwards your real credentials. It forwards to one upstream, binds to loopback, allows three headers, follows no redirects and logs nothing. Keep it that way — if you widen any of those, say why in a comment.

Every page inlines the spec into a `<script>` block, so `bin/build-swagger.php` escapes `</` before injecting it — a description containing a literal `</script>` would otherwise close the block early and leave a blank page. `tests/DocsPageTest.php` builds every page for real and reads it back, so that stays honest. If you edit a template, keep its placeholders exactly as they are; the builder fails loudly if one goes missing, rather than writing a page with no spec in it.

`build/` is gitignored — the spec is a build artifact, published by CI, not committed. `assets/domeneshop-openapi.json` is the *upstream* document, kept for diffing; do not edit it.

When you change a request or response shape, change the attributes in the same commit. `tests/OpenApiTest.php` asserts the corrections we make to the official document, so if you deliberately change one of them, update that test and the comparison table in the core README.

## Repository settings

These live on GitHub rather than in the repo, so they are recorded here — otherwise they are invisible to anyone reading the code.

**Branch ruleset `default branch`** (id `23057841`), targeting `~DEFAULT_BRANCH` so it follows the default branch if it is ever renamed:

| Rule | Effect |
| --- | --- |
| `pull_request` | no direct pushes; 0 required approvals |
| `required_status_checks` | all 9 CI checks must pass |
| `non_fast_forward` | no force-pushing |
| `deletion` | the branch cannot be deleted |

**Zero required approvals is deliberate**, not an oversight: GitHub will not let you approve your own pull request, so on a solo repo requiring one would make every self-authored PR unmergeable. The gate here is CI, not a second pair of eyes. If a second maintainer ever joins, raise it to 1.

There are **no bypass actors** — the rules apply to the owner too.

`strict_required_status_checks_policy` is **off**. With daily Dependabot PRs, requiring every branch to be up to date before merging means each merge invalidates the others and triggers a rebase-and-rerun storm. The trade is that two individually-passing PRs can still conflict semantically; if that ever bites, turn it on.

GitHub defaults `require_extra_approval_for_unattributed_changes` to **on**. It should never fire for normally-authored commits, but it is worth knowing about: if a merge is ever blocked with no obvious cause, that is the first thing to check.

Also set: **auto-merge allowed** (the Dependabot workflow needs it) and **delete branch on merge**.

To change enforcement without editing the rules — for example if an initial or emergency push is rejected:

```bash
gh api -X PUT repos/sebastka/domeneshop-sdk/rulesets/23057841 -f enforcement=disabled
# ... push ...
gh api -X PUT repos/sebastka/domeneshop-sdk/rulesets/23057841 -f enforcement=active
```

The nine required checks are named after the CI jobs. **Renaming a job renames its check**, which silently stops it being required — the ruleset matches on name. Update the ruleset in the same commit if you rename one.

## Dependency updates

Dependabot checks the three Composer packages, the Go module and the GitHub Actions **daily** — which in Dependabot's vocabulary means weekdays, Monday to Friday, at 05:00 Europe/Oslo. There is no interval shorter than a day. When you want an update sooner, editing `.github/dependabot.yml` triggers an immediate check, and *Insights → Dependency graph → Dependabot* has a per-ecosystem **Check for updates** button.

`open-pull-requests-limit` is raised to 10 from its default of 5, because the auto-merge workflow below deliberately leaves major bumps open. Those accumulate against the limit, and once it is reached Dependabot stops opening *any* new PRs for that ecosystem — including the patch and minor ones meant to merge promptly. Triage majors, or the queue backs up whatever the limit is. [`dependabot-auto-merge.yaml`](.github/workflows/dependabot-auto-merge.yaml) approves and auto-merges **patch and minor** bumps; **major** bumps are left open and get a comment saying why. A major release can change behaviour, drop a PHP or Go version, or rename an action's inputs, and none of that is something CI reliably catches.

Two repository settings have to be in place, or this does not do what it looks like it does:

1. **Settings → General → Allow auto-merge** must be on, or `gh pr merge --auto` fails outright.
2. **The default branch needs protection with required status checks** — the `php`, `style` and `provider` jobs, and the OpenAPI one. This is the one that matters: `--auto` merges as soon as the PR is mergeable, so with no required checks configured it merges *immediately*, without waiting for the suite. That turns the workflow into "merge every non-major bump blind", which is the opposite of the intent.

The workflow never checks out or executes the PR's code, and passes Dependabot's metadata through the environment rather than interpolating it into the shell, so a crafted dependency name cannot become a command.

## Releasing

Releases are cut by the **Release** workflow — Actions → Release → *Run workflow* — with the version as an input (`0.2.0`, no leading `v`). There is a `dry_run` box that validates everything and stops short of tagging.

It never commits. Bump the version in a normal pull request first, then dispatch the workflow; it verifies and tags. Before creating the tag it checks that:

- the version is `MAJOR.MINOR.PATCH` — no leading `v`, and no pre-release suffix, because the `VERSION` constants carry none and a `0.2.0-rc.1` tag could never match them;
- the tag does not already exist, since releases are immutable;
- all four declared versions agree with each other and with the requested tag (`php tools/check-versions.php 0.2.0` runs the same check locally);
- the commit being tagged has passing checks.

The tag is pushed with the **GitHub App token, not `GITHUB_TOKEN`** — and that is not incidental. A tag pushed with the default token does not trigger other workflows, so `split.yaml` would never run: the release would appear to succeed and quietly publish nothing. The App therefore needs access to `domeneshop-sdk` itself, not only the four split repos.

Everything downstream hangs off that tag: `split.yaml` mirrors it into the four package repos, Packagist picks up the new version from the three PHP splits, and the provider's own release workflow builds the signed, registry-shaped artifacts.

`tools/check-versions.php` also runs on every push, so version drift surfaces on the pull request rather than when a release is refused weeks later.

## Reporting the API's behaviour

This client targets the **live** API, which in several places differs from the published documentation. Everything found so far is catalogued in **[NOTES.md](NOTES.md)**: defects in the published OpenAPI definition, gaps between the prose reference and the schema, the genuine API behaviours we preserve rather than correct, and what the production API actually does.

If you find another discrepancy, **add it to NOTES.md** rather than quietly working around it in code — an unexplained workaround is indistinguishable from a bug. Two conventions there are worth keeping:

- Say how it was established. A mechanical diff against the published document and a call against the production API are different kinds of evidence, and the tables distinguish them.
- If the live API turns out to disagree with an entry, that is the more valuable finding. Correct the entry and say so, rather than deleting it.

The core package's README carries a short version of the spec comparison, because it ships to its own repository and has to stand alone. If you change the comparison table in one, change it in the other.
