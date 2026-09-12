<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Cli;

use Sebastka\Domeneshop\Model\ARecord;
use Sebastka\Domeneshop\Model\AaaaRecord;
use Sebastka\Domeneshop\Model\CnameRecord;
use Sebastka\Domeneshop\Model\DnsRecord;
use Sebastka\Domeneshop\Model\MxRecord;
use Sebastka\Domeneshop\Model\RecordType;
use Sebastka\Domeneshop\Model\SrvRecord;
use Sebastka\Domeneshop\Model\TlsaRecord;
use Sebastka\Domeneshop\Model\TxtRecord;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Builds a {@see DnsRecord} from `dns:create` / `dns:update` input.
 *
 * The record types need different extra fields, but a flag-based CLI cannot
 * express that the way the constructors do. So every extra is an optional flag,
 * and this class enforces the per-type requirements — naming the missing flag
 * rather than letting a zero default reach the API.
 *
 * @internal
 */
final class RecordFactory
{
    /** The option names each record type requires beyond host and data. */
    private const REQUIRED_EXTRAS = [
        'MX' => ['priority'],
        'SRV' => ['priority', 'weight', 'port'],
        'TLSA' => ['usage', 'selector', 'dtype'],
    ];

    public static function fromInput(InputInterface $input): DnsRecord
    {
        $rawType = strtoupper((string) $input->getArgument('type'));
        $type = RecordType::tryFrom($rawType) ?? throw new \InvalidArgumentException(\sprintf(
            'Unknown record type "%s". Supported: %s.',
            $rawType,
            implode(', ', array_column(RecordType::cases(), 'value')),
        ));

        $host = (string) $input->getArgument('host');
        $data = (string) $input->getArgument('data');
        $ttl = self::intOption($input, 'ttl');

        foreach (self::REQUIRED_EXTRAS[$type->value] ?? [] as $required) {
            if ($input->getOption($required) === null) {
                throw new \InvalidArgumentException(\sprintf(
                    'A %s record requires --%s.',
                    $type->value,
                    $required,
                ));
            }
        }

        return match ($type) {
            RecordType::A => new ARecord($host, $data, $ttl),
            RecordType::AAAA => new AaaaRecord($host, $data, $ttl),
            RecordType::CNAME => new CnameRecord($host, $data, $ttl),
            RecordType::TXT => new TxtRecord($host, $data, $ttl),
            RecordType::MX => new MxRecord($host, $data, (int) self::intOption($input, 'priority'), $ttl),
            RecordType::SRV => new SrvRecord(
                $host,
                $data,
                (int) self::intOption($input, 'priority'),
                (int) self::intOption($input, 'weight'),
                (int) self::intOption($input, 'port'),
                $ttl,
            ),
            RecordType::TLSA => new TlsaRecord(
                $host,
                $data,
                (int) self::intOption($input, 'usage'),
                (int) self::intOption($input, 'selector'),
                (int) self::intOption($input, 'dtype'),
                $ttl,
            ),
        };
    }

    /** The flags every record command accepts, in a shape {@see ApiCommand} takes. */
    public static function options(): array
    {
        return [
            ['ttl', 'value', 'TTL in seconds (60-604800, a multiple of 60). Omit for the API default of 3600'],
            ['priority', 'value', 'MX/SRV priority (required for MX and SRV)'],
            ['weight', 'value', 'SRV weight (required for SRV)'],
            ['port', 'value', 'SRV port (required for SRV)'],
            ['usage', 'value', 'TLSA usage: 0 PKIX-TA, 1 PKIX-EE, 2 DANE-TA, 3 DANE-EE (required for TLSA)'],
            ['selector', 'value', 'TLSA selector: 0 full certificate, 1 subject public key (required for TLSA)'],
            ['dtype', 'value', 'TLSA matching type: 0 exact, 1 SHA-256, 2 SHA-512 (required for TLSA)'],
        ];
    }

    private static function intOption(InputInterface $input, string $name): ?int
    {
        $value = $input->getOption($name);
        if ($value === null) {
            return null;
        }

        if (! is_numeric($value)) {
            throw new \InvalidArgumentException(\sprintf('--%s must be a number, got "%s".', $name, (string) $value));
        }

        return (int) $value;
    }
}
