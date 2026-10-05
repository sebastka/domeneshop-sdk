<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Dashboard\Cli;

use Sebastka\Domeneshop\Cli\ApiCommand;
use Sebastka\Domeneshop\Cli\Output;
use Sebastka\Domeneshop\Dashboard\DashboardClient;
use Sebastka\Domeneshop\Dashboard\Model\Contact;
use Sebastka\Domeneshop\Dashboard\Model\DsRecord;
use Sebastka\Domeneshop\Dashboard\Resource\Pages;
use Symfony\Component\Console\Input\InputInterface;

/**
 * The `dashboard:*` commands, registered by the `domeneshop` CLI when this
 * package is installed alongside it.
 *
 * They are namespaced under `dashboard:` on purpose. Everything else in that
 * CLI talks to a documented API with a generated OpenAPI document and a live
 * contract suite; these scrape a web page that can change without notice.
 * Sharing a command prefix would imply the two are equally dependable.
 *
 * Reuses {@see ApiCommand} from the CLI package so error handling, `--json` and
 * the lazy client are identical — but the closure receives a
 * {@see DashboardClient} rather than the API client. Third parameter aside, the
 * shape is the same.
 */
final class Commands
{
    /**
     * @param \Closure(): DashboardClient|null $clientProvider Override client construction (tests inject a stub).
     *
     * @return list<ApiCommand>
     */
    public static function all(?\Closure $clientProvider = null): array
    {
        $provider = $clientProvider ?? static fn (): DashboardClient => CookieFactory::create();

        return [
            self::cookie($provider),
            self::nameservers($provider),
            self::dnssec($provider),
            self::glue($provider),
            self::contacts($provider),
            self::fetch($provider),
        ];
    }

    /**
     * @param \Closure(): DashboardClient $provider
     */
    private static function cookie(\Closure $provider): ApiCommand
    {
        $command = new ApiCommand(
            'dashboard:cookie',
            'Check the dashboard session cookie, and explain how to capture one',
            [],
            [],
            static function (InputInterface $in, Output $out, mixed $unused) use ($provider): int {
                try {
                    $client = $provider();
                } catch (\RuntimeException $e) {
                    // No cookie configured yet: that is the normal first run, so
                    // explain rather than just failing.
                    $out->note($e->getMessage());
                    $out->note('');
                    $out->note(self::howToCapture());

                    return 1;
                }

                if (! $client->isSignedIn()) {
                    $out->note('The configured cookie is not accepted — it has probably expired.');
                    $out->note('');
                    $out->note(self::howToCapture());

                    return 1;
                }

                return $out->done('The dashboard session cookie works.', ['signed_in' => true]);
            },
        );

        // The API client is irrelevant here; never build one.
        $command->setClientProvider(static fn (): mixed => null);

        return $command;
    }

    /**
     * @param \Closure(): DashboardClient $provider
     */
    private static function fetch(\Closure $provider): ApiCommand
    {
        $command = new ApiCommand(
            'dashboard:fetch',
            'Print a dashboard page as raw HTML (use it to capture a parser fixture)',
            [
                ['domain-id', true, 'The numeric domain id (see domains:list)'],
                ['page', true, 'Which page: ' . implode(', ', Pages::names())],
            ],
            [],
            static function (InputInterface $in, Output $out, mixed $unused) use ($provider): int {
                $html = $provider()->pages()->fetch(self::domainId($in), (string) $in->getArgument('page'));

                // Straight to stdout: this is meant to be redirected to a file.
                echo $html;

                return 0;
            },
        );

        $command->setClientProvider(static fn (): mixed => null);

        return $command;
    }

    /**
     * @param \Closure(): DashboardClient $provider
     */
    private static function nameservers(\Closure $provider): ApiCommand
    {
        return self::read(
            'dashboard:nameservers',
            'Show a domain\'s nameservers and whether DNSSEC is enabled',
            static function (InputInterface $in, Output $out) use ($provider): int {
                $ns = $provider()->nameservers()->forDomain(self::domainId($in));

                return $out->detail(['Nameservers' => $ns->hosts, 'DNSSEC' => $ns->dnssec], $ns->toArray());
            },
        );
    }

    /**
     * @param \Closure(): DashboardClient $provider
     */
    private static function dnssec(\Closure $provider): ApiCommand
    {
        return self::read(
            'dashboard:dnssec',
            'Show a domain\'s DNSSEC state, with its DS records where they are yours to see',
            static function (InputInterface $in, Output $out) use ($provider): int {
                $status = $provider()->dnssec()->statusForDomain(self::domainId($in));

                // One JSON shape for both setups, so a script can rely on it:
                // `records` is null when they are Domeneshop's, never [].
                if ($out->isJson()) {
                    return $out->detail([], $status->toArray());
                }

                if ($status->records === null) {
                    $out->note('This domain is on Domeneshop\'s nameservers, so Domeneshop signs the zone and');
                    $out->note('publishes the DS records itself. The dashboard offers none to read.');
                    $out->note('');

                    return $out->detail(
                        ['DNSSEC' => $status->enabled, 'DS records' => 'managed by Domeneshop'],
                        $status->toArray(),
                    );
                }

                return $out->table(
                    ['Keytag', 'Algorithm', 'Digest type', 'Digest'],
                    array_map(static fn (DsRecord $r): array => [$r->keytag, $r->algorithm, $r->digestType, $r->digest], $status->records),
                    array_map(static fn (DsRecord $r): array => $r->toArray(), $status->records),
                    'No DS records.',
                );
            },
        );
    }

    /**
     * @param \Closure(): DashboardClient $provider
     */
    private static function glue(\Closure $provider): ApiCommand
    {
        return self::read(
            'dashboard:glue',
            'List a domain\'s glue records (reports, rather than guesses, when there are any)',
            static function (InputInterface $in, Output $out) use ($provider): int {
                $records = $provider()->glue()->forDomain(self::domainId($in));

                return $out->table(
                    ['Host', 'IP'],
                    array_map(static fn ($r): array => [$r->host, $r->ip], $records),
                    array_map(static fn ($r): array => $r->toArray(), $records),
                    'No glue records.',
                );
            },
        );
    }

    /**
     * @param \Closure(): DashboardClient $provider
     */
    private static function contacts(\Closure $provider): ApiCommand
    {
        return self::read(
            'dashboard:contacts',
            'Show a domain\'s contacts (they vary by TLD) and the account\'s billing contact',
            static function (InputInterface $in, Output $out) use ($provider): int {
                $contacts = $provider()->contacts()->forDomain(self::domainId($in));

                $fields = [];
                foreach (['Owner' => $contacts->owner, 'Admin' => $contacts->admin, 'Tech' => $contacts->tech] as $role => $contact) {
                    if ($contact !== null) {
                        $fields += self::contactFields($role, $contact);
                    }
                }
                foreach ($contacts->billing->toArray() as $key => $value) {
                    $fields['Billing ' . str_replace('_', ' ', $key)] = $value;
                }
                $fields['Hide email in WHOIS'] = $contacts->hideEmail;
                $fields['Hide personal data in WHOIS'] = $contacts->hidePersonalData;

                return $out->detail($fields, $contacts->toArray());
            },
        );
    }

    /**
     * A read command: one `domain-id` argument, and the API client never built.
     *
     * @param \Closure(InputInterface, Output): int $handler
     */
    private static function read(string $name, string $description, \Closure $handler): ApiCommand
    {
        $command = new ApiCommand(
            $name,
            $description,
            [['domain-id', true, 'The numeric domain id (see domains:list)']],
            [],
            static fn (InputInterface $in, Output $out, mixed $unused): int => $handler($in, $out),
        );

        $command->setClientProvider(static fn (): mixed => null);

        return $command;
    }

    /**
     * One role's fields for the detail table. Empty fields are left out: which
     * ones exist is the TLD's choice, and a column of dashes says nothing.
     *
     * @return array<string, mixed>
     */
    private static function contactFields(string $role, Contact $contact): array
    {
        $fields = [];
        foreach ($contact->toArray() as $key => $value) {
            if ($key === 'extra') {
                foreach ($contact->extra as $extraKey => $extraValue) {
                    $fields[\sprintf('%s %s', $role, $extraKey)] = $extraValue;
                }

                continue;
            }

            if ($value !== null) {
                $fields[\sprintf('%s %s', $role, str_replace('_', ' ', $key))] = $value;
            }
        }

        return $fields;
    }

    private static function domainId(InputInterface $input): int
    {
        $value = $input->getArgument('domain-id');
        if (! is_numeric($value)) {
            throw new \InvalidArgumentException(\sprintf(
                'The domain-id argument must be a number, got "%s".',
                \is_scalar($value) ? (string) $value : \gettype($value),
            ));
        }

        return (int) $value;
    }

    private static function howToCapture(): string
    {
        return <<<'TEXT'
        To capture a session cookie:

          1. Sign in at https://domene.shop/login in your normal browser.
          2. Open developer tools, Network tab, and reload any /admin page.
          3. On that request, copy the `sessionid=...` pair from the `Cookie:`
             request header (the whole header value works too).
          4. Store it, either as an environment variable:

                 export DOMENESHOP_DASHBOARD_COOKIE='...'

             or in ~/.config/domeneshop/config.ini:

                 dashboard_cookie = "..."

             then `chmod 600` that file.

        Use your ordinary browser rather than a headless one: the login form
        submits a WebGL device fingerprint, and a headless browser reports a
        software renderer, so it looks like a different machine.

        This cookie grants your entire account — billing, transfers, deletion —
        not the scoped access an API token gives. It also expires, so expect to
        repeat this occasionally.
        TEXT;
    }
}
