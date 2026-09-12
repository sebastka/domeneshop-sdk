<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Resource;

use Sebastka\Domeneshop\Exception\NotFoundException;
use OpenApi\Attributes as OA;
use Sebastka\Domeneshop\Http\Transport;

/**
 * Dynamic DNS updates (`/dyndns/update`).
 *
 * This is the one endpoint that creates records as a side effect: it sets the
 * A/AAAA record for a hostname, creating it if absent and updating it if not.
 * It is meant for hosts whose address changes — a home router, a dynamic VPS.
 */
final class DynDns
{
    /** The API accepts at most this many addresses in one call. */
    public const MAX_ADDRESSES = 9;

    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * Point one or more hostnames at one or more addresses.
     *
     * @param string|list<string>      $hostname Fully qualified, without a trailing
     *                                           dot, e.g. `home.example.com`.
     * @param string|list<string>|null $myip     IPv4 and/or IPv6 addresses. Null
     *                                           uses the public IP this request
     *                                           arrives from — the usual choice
     *                                           when calling from the host itself.
     *
     * @throws \InvalidArgumentException when no hostname is given, or more than
     *                                   {@see self::MAX_ADDRESSES} addresses are.
     * @throws NotFoundException         when the domain is not in the account.
     */
    #[OA\Get(
        path: '/dyndns/update',
        operationId: 'updateDynDns',
        summary: 'Update dynamic DNS',
        description: "Set the A/AAAA record for a hostname, creating it if absent and updating it if not.\n\n"
            . 'This is the only endpoint that creates records as a side effect. It is meant for hosts whose '
            . 'address changes — a home router, a dynamic VPS.',
        tags: ['ddns'],
        security: [['basicAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'hostname',
                in: 'query',
                required: true,
                description: 'The fully qualified domain (FQDN) to update, without a trailing dot. Comma-separate for several.',
                schema: new OA\Schema(type: 'string', format: 'hostname', example: 'home.example.com'),
            ),
            new OA\Parameter(
                name: 'myip',
                in: 'query',
                required: false,
                description: 'The IPv4/IPv6 address to set. Omit to use the IP the request arrives from. '
                    . 'Comma-separate for several, up to 9.',
                schema: new OA\Schema(type: 'string', example: '203.0.113.10'),
            ),
        ],
        responses: [
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 204, description: 'Successful operation'),
            new OA\Response(response: 404, description: 'Domain not found'),
        ],
    )]
    public function update(string|array $hostname, string|array|null $myip = null): void
    {
        $hostnames = self::toList($hostname);
        if ($hostnames === []) {
            throw new \InvalidArgumentException('At least one hostname is required.');
        }

        $addresses = $myip === null ? [] : self::toList($myip);
        if (\count($addresses) > self::MAX_ADDRESSES) {
            throw new \InvalidArgumentException(\sprintf(
                'At most %d addresses may be updated at once, got %d.',
                self::MAX_ADDRESSES,
                \count($addresses),
            ));
        }

        $this->transport->get('/dyndns/update', [
            'hostname' => implode(',', $hostnames),
            'myip' => $addresses === [] ? null : implode(',', $addresses),
        ]);
    }

    /**
     * @param string|list<string> $value
     *
     * @return list<string>
     */
    private static function toList(string|array $value): array
    {
        $items = \is_string($value) ? explode(',', $value) : $value;
        $items = array_map(static fn (string $item): string => trim($item), $items);

        return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
    }
}
