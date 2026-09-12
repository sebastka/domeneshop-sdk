<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Cli;

use Sebastka\Domeneshop\DomeneshopClient;
use Sebastka\Domeneshop\Model\HttpForward;
use Sebastka\Domeneshop\Model\InvoiceStatus;
use Sebastka\Domeneshop\Model\RecordType;
use Symfony\Component\Console\Input\InputInterface;

/**
 * The full command catalogue: one {@see ApiCommand} per Domeneshop API
 * operation, grouped by resource. Keeping every command in one declarative
 * registry keeps them consistent and easy to scan, instead of a class file per
 * near-identical command.
 */
final class Commands
{
    /**
     * @param \Closure(): DomeneshopClient|null $clientProvider Override client construction (tests inject a mock).
     *
     * @return list<ApiCommand>
     */
    public static function all(?\Closure $clientProvider = null): array
    {
        $commands = [
            ...self::domains(),
            ...self::dns(),
            ...self::forwards(),
            ...self::invoices(),
            ...self::dynDns(),
        ];

        if ($clientProvider !== null) {
            foreach ($commands as $command) {
                $command->setClientProvider($clientProvider);
            }
        }

        return $commands;
    }

    /** @return list<ApiCommand> */
    private static function domains(): array
    {
        return [
            new ApiCommand('domains:list', 'List the domains in the account', [], [
                ['domain', 'value', 'Only domains whose name contains this string'],
            ], static function (InputInterface $in, Output $out, DomeneshopClient $c): int {
                $domains = $c->domains()->list(self::str($in->getOption('domain')));

                return $out->table(
                    ['ID', 'Domain', 'Status', 'Expires', 'Renew', 'DNS', 'Email', 'Webhotel'],
                    array_map(static fn ($d): array => [
                        $d->id,
                        $d->domain,
                        $d->status,
                        $d->expiryDate,
                        $d->renew,
                        $d->services?->dns,
                        $d->services?->email,
                        $d->services?->webhotel,
                    ], $domains),
                    array_map(Format::domain(...), $domains),
                    'No domains found.',
                );
            }),

            new ApiCommand('domains:get', 'Show one domain by id', [
                ['domain-id', true, 'The numeric domain id (see domains:list)'],
            ], [], static function (InputInterface $in, Output $out, DomeneshopClient $c): int {
                $d = $c->domains()->get(self::id($in, 'domain-id'));

                return $out->detail([
                    'ID' => $d->id,
                    'Domain' => $d->domain,
                    'Status' => $d->status,
                    'Registrant' => $d->registrant,
                    'Registered' => $d->registeredDate,
                    'Expires' => $d->expiryDate,
                    'Auto-renew' => $d->renew,
                    'Nameservers' => $d->nameservers,
                    'Registrar' => $d->services?->registrar,
                    'DNS' => $d->services?->dns,
                    'Email' => $d->services?->email,
                    'Webhotel' => $d->services?->webhotel,
                ], Format::domain($d));
            }),
        ];
    }

    /** @return list<ApiCommand> */
    private static function dns(): array
    {
        $recordArguments = [
            ['domain-id', true, 'The numeric domain id (see domains:list)'],
            ['type', true, 'Record type: A, AAAA, CNAME, MX, SRV, TLSA or TXT'],
            ['host', true, 'The host/subdomain; @ for the zone apex'],
            ['data', true, 'The record value (address, hostname, text or TLSA hash)'],
        ];

        return [
            new ApiCommand('dns:list', 'List a domain\'s DNS records', [
                ['domain-id', true, 'The numeric domain id (see domains:list)'],
            ], [
                ['host', 'value', 'Only records for this host'],
                ['type', 'value', 'Only records of this type'],
            ], static function (InputInterface $in, Output $out, DomeneshopClient $c): int {
                $type = self::str($in->getOption('type'));
                $records = $c->dns()->list(
                    self::id($in, 'domain-id'),
                    self::str($in->getOption('host')),
                    $type === null ? null : self::recordType($type),
                );

                return $out->table(
                    ['ID', 'Host', 'Type', 'TTL', 'Data', 'Extra'],
                    array_map(static fn ($r): array => [
                        $r->id,
                        $r->host,
                        $r->type(),
                        $r->ttl,
                        Format::record($r)['data'] ?? null,
                        self::extras($r),
                    ], $records),
                    array_map(Format::record(...), $records),
                    'No DNS records found.',
                );
            }),

            new ApiCommand('dns:get', 'Show one DNS record', [
                ['domain-id', true, 'The numeric domain id'],
                ['record-id', true, 'The numeric record id (see dns:list)'],
            ], [], static function (InputInterface $in, Output $out, DomeneshopClient $c): int {
                $record = $c->dns()->get(self::id($in, 'domain-id'), self::id($in, 'record-id'));
                $fields = Format::record($record);

                return $out->detail(
                    array_combine(array_map(ucfirst(...), array_keys($fields)), array_values($fields)),
                    $fields,
                );
            }),

            new ApiCommand(
                'dns:create',
                'Create a DNS record',
                $recordArguments,
                RecordFactory::options(),
                static function (InputInterface $in, Output $out, DomeneshopClient $c): int {
                    $id = $c->dns()->create(self::id($in, 'domain-id'), RecordFactory::fromInput($in));

                    return $out->done(\sprintf('Created DNS record %d.', $id), ['id' => $id]);
                },
            ),

            new ApiCommand(
                'dns:update',
                'Replace a DNS record (every field of the record type is required)',
                [
                    ['domain-id', true, 'The numeric domain id'],
                    ['record-id', true, 'The numeric record id (see dns:list)'],
                    ['type', true, 'Record type: A, AAAA, CNAME, MX, SRV, TLSA or TXT'],
                    ['host', true, 'The host/subdomain; @ for the zone apex'],
                    ['data', true, 'The record value'],
                ],
                RecordFactory::options(),
                static function (InputInterface $in, Output $out, DomeneshopClient $c): int {
                    $recordId = self::id($in, 'record-id');
                    $c->dns()->update(self::id($in, 'domain-id'), $recordId, RecordFactory::fromInput($in));

                    return $out->done(\sprintf('Updated DNS record %d.', $recordId), ['id' => $recordId]);
                },
            ),

            new ApiCommand('dns:delete', 'Delete a DNS record', [
                ['domain-id', true, 'The numeric domain id'],
                ['record-id', true, 'The numeric record id (see dns:list)'],
            ], [], static function (InputInterface $in, Output $out, DomeneshopClient $c): int {
                $recordId = self::id($in, 'record-id');
                $c->dns()->delete(self::id($in, 'domain-id'), $recordId);

                return $out->done(\sprintf('Deleted DNS record %d.', $recordId), ['id' => $recordId]);
            }),
        ];
    }

    /** @return list<ApiCommand> */
    private static function forwards(): array
    {
        return [
            new ApiCommand('forwards:list', 'List a domain\'s HTTP forwards', [
                ['domain-id', true, 'The numeric domain id (see domains:list)'],
            ], [], static function (InputInterface $in, Output $out, DomeneshopClient $c): int {
                $forwards = $c->forwards()->list(self::id($in, 'domain-id'));

                return $out->table(
                    ['Host', 'URL', 'Frame'],
                    array_map(static fn ($f): array => [$f->host, $f->url, $f->frame], $forwards),
                    array_map(Format::forward(...), $forwards),
                    'No forwards found.',
                );
            }),

            new ApiCommand('forwards:get', 'Show one HTTP forward', [
                ['domain-id', true, 'The numeric domain id'],
                ['host', true, 'The host/subdomain; @ for the zone apex'],
            ], [], static function (InputInterface $in, Output $out, DomeneshopClient $c): int {
                $f = $c->forwards()->get(self::id($in, 'domain-id'), (string) $in->getArgument('host'));

                return $out->detail(
                    ['Host' => $f->host, 'URL' => $f->url, 'Frame' => $f->frame],
                    Format::forward($f),
                );
            }),

            new ApiCommand('forwards:create', 'Create an HTTP forward', [
                ['domain-id', true, 'The numeric domain id'],
                ['host', true, 'The host/subdomain; @ for the zone apex'],
                ['url', true, 'The target URL, including scheme'],
            ], [
                ['frame', 'none', 'Serve the target in an iframe instead of redirecting (not recommended)'],
            ], static function (InputInterface $in, Output $out, DomeneshopClient $c): int {
                $forward = new HttpForward(
                    (string) $in->getArgument('host'),
                    (string) $in->getArgument('url'),
                    (bool) $in->getOption('frame'),
                );
                $c->forwards()->create(self::id($in, 'domain-id'), $forward);

                return $out->done(\sprintf('Created forward for "%s".', $forward->host), Format::forward($forward));
            }),

            new ApiCommand('forwards:update', 'Change where an HTTP forward points', [
                ['domain-id', true, 'The numeric domain id'],
                ['host', true, 'The host to update; the host itself cannot be changed'],
                ['url', true, 'The new target URL, including scheme'],
            ], [
                ['frame', 'none', 'Serve the target in an iframe instead of redirecting (not recommended)'],
            ], static function (InputInterface $in, Output $out, DomeneshopClient $c): int {
                $host = (string) $in->getArgument('host');
                $stored = $c->forwards()->update(self::id($in, 'domain-id'), $host, new HttpForward(
                    $host,
                    (string) $in->getArgument('url'),
                    (bool) $in->getOption('frame'),
                ));

                return $out->done(\sprintf('Updated forward for "%s".', $host), Format::forward($stored));
            }),

            new ApiCommand('forwards:delete', 'Delete an HTTP forward', [
                ['domain-id', true, 'The numeric domain id'],
                ['host', true, 'The host/subdomain; @ for the zone apex'],
            ], [], static function (InputInterface $in, Output $out, DomeneshopClient $c): int {
                $host = (string) $in->getArgument('host');
                $c->forwards()->delete(self::id($in, 'domain-id'), $host);

                return $out->done(\sprintf('Deleted forward for "%s".', $host), ['host' => $host]);
            }),
        ];
    }

    /** @return list<ApiCommand> */
    private static function invoices(): array
    {
        return [
            new ApiCommand('invoices:list', 'List invoices from the past three years', [], [
                ['status', 'value', 'Only invoices with this status: unpaid, paid or settled'],
            ], static function (InputInterface $in, Output $out, DomeneshopClient $c): int {
                $status = self::str($in->getOption('status'));
                $invoices = $c->invoices()->list($status === null ? null : self::invoiceStatus($status));

                return $out->table(
                    ['ID', 'Type', 'Amount', 'Currency', 'Issued', 'Due', 'Paid', 'Status'],
                    array_map(static fn ($i): array => [
                        $i->id,
                        $i->type,
                        $i->amount,
                        $i->currency,
                        $i->issuedDate,
                        $i->dueDate,
                        $i->paidDate,
                        $i->status,
                    ], $invoices),
                    array_map(Format::invoice(...), $invoices),
                    'No invoices found.',
                );
            }),

            new ApiCommand('invoices:get', 'Show one invoice by number', [
                ['invoice-id', true, 'The invoice number'],
            ], [], static function (InputInterface $in, Output $out, DomeneshopClient $c): int {
                $i = $c->invoices()->get(self::id($in, 'invoice-id'));

                return $out->detail([
                    'ID' => $i->id,
                    'Type' => $i->type,
                    'Amount' => $i->amount,
                    'Currency' => $i->currency,
                    'Issued' => $i->issuedDate,
                    'Due' => $i->dueDate,
                    'Paid' => $i->paidDate,
                    'Status' => $i->status,
                    'URL' => $i->url,
                ], Format::invoice($i));
            }),
        ];
    }

    /** @return list<ApiCommand> */
    private static function dynDns(): array
    {
        return [
            new ApiCommand(
                'dyndns:update',
                'Point a hostname at an address, creating the A/AAAA record if needed',
                [
                    ['hostname', true, 'Fully qualified hostname, no trailing dot. Comma-separate for several'],
                ],
                [
                    ['myip', 'value', 'IPv4/IPv6 address, comma-separated for several. Omit to use this machine\'s public IP'],
                ],
                static function (InputInterface $in, Output $out, DomeneshopClient $c): int {
                    $hostname = (string) $in->getArgument('hostname');
                    $myip = self::str($in->getOption('myip'));
                    $c->dynDns()->update($hostname, $myip);

                    return $out->done(
                        \sprintf('Updated "%s" to %s.', $hostname, $myip ?? 'this machine\'s public IP'),
                        ['hostname' => $hostname, 'myip' => $myip],
                    );
                },
            ),
        ];
    }

    /** The type-specific fields of a record, rendered for the list table's "Extra" column. */
    private static function extras(\Sebastka\Domeneshop\Model\DnsRecord $record): string
    {
        $extras = Format::record($record);
        unset($extras['id'], $extras['host'], $extras['type'], $extras['ttl'], $extras['data']);

        $parts = [];
        foreach ($extras as $key => $value) {
            $parts[] = $key . '=' . Format::cell($value);
        }

        return $parts === [] ? '-' : implode(' ', $parts);
    }

    /** Read a required numeric argument, rejecting non-numeric input up front. */
    private static function id(InputInterface $input, string $name): int
    {
        $value = $input->getArgument($name);
        if (! is_numeric($value)) {
            throw new \InvalidArgumentException(\sprintf(
                'The %s argument must be a number, got "%s".',
                $name,
                \is_scalar($value) ? (string) $value : \gettype($value),
            ));
        }

        return (int) $value;
    }

    private static function str(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }

    private static function recordType(string $value): RecordType
    {
        return RecordType::tryFrom(strtoupper($value)) ?? throw new \InvalidArgumentException(\sprintf(
            'Unknown record type "%s". Supported: %s.',
            $value,
            implode(', ', array_column(RecordType::cases(), 'value')),
        ));
    }

    private static function invoiceStatus(string $value): InvoiceStatus
    {
        return InvoiceStatus::tryFrom(strtolower($value)) ?? throw new \InvalidArgumentException(\sprintf(
            'Unknown invoice status "%s". Supported: %s.',
            $value,
            implode(', ', array_column(InvoiceStatus::cases(), 'value')),
        ));
    }
}
