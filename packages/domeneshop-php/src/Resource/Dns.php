<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Resource;

use Sebastka\Domeneshop\Exception\NotFoundException;
use Sebastka\Domeneshop\Http\Transport;
use OpenApi\Attributes as OA;
use Sebastka\Domeneshop\Model\DnsRecord;
use Sebastka\Domeneshop\Model\RecordType;

/** DNS records for one domain (`/domains/{domainId}/dns`). */
final class Dns
{
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * List a domain's DNS records, optionally narrowed by host and/or type.
     *
     * @return list<DnsRecord>
     */
    #[OA\Get(
        path: '/domains/{domainId}/dns',
        operationId: 'listDnsRecords',
        summary: 'List DNS records',
        description: 'List a domain\'s DNS records, optionally narrowed by host and/or type.',
        tags: ['dns'],
        security: [['basicAuth' => []]],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/DomainId'),
            new OA\Parameter(name: 'host', in: 'query', required: false, description: 'Only return records whose `host` matches this string.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'type', in: 'query', required: false, description: 'Only return records of this type.', schema: new OA\Schema(type: 'string', enum: ['A', 'AAAA', 'CNAME', 'MX', 'SRV', 'TLSA', 'TXT'])),
        ],
        responses: [
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 200, description: 'Successful operation', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/DNSRecord'))),
            new OA\Response(response: 404, description: 'Domain not found'),
        ],
    )]
    public function list(int $domainId, ?string $host = null, ?RecordType $type = null): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = (array) $this->transport->get(\sprintf('/domains/%d/dns', $domainId), [
            'host' => $host,
            'type' => $type?->value,
        ]);

        return array_values(array_map(DnsRecord::fromArray(...), $rows));
    }

    /**
     * Fetch a single DNS record.
     *
     * @throws NotFoundException when the domain or record does not exist.
     */
    #[OA\Get(
        path: '/domains/{domainId}/dns/{recordId}',
        operationId: 'getDnsRecord',
        summary: 'Get DNS record',
        description: 'Fetch a single DNS record.',
        tags: ['dns'],
        security: [['basicAuth' => []]],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/DomainId'),
            new OA\Parameter(ref: '#/components/parameters/RecordId'),
        ],
        responses: [
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 200, description: 'Successful operation', content: new OA\JsonContent(ref: '#/components/schemas/DNSRecord')),
            new OA\Response(response: 404, description: 'Domain or record not found'),
        ],
    )]
    public function get(int $domainId, int $recordId): DnsRecord
    {
        /** @var array<string, mixed> $data */
        $data = (array) $this->transport->get(\sprintf('/domains/%d/dns/%d', $domainId, $recordId));

        return DnsRecord::fromArray($data);
    }

    /**
     * Create a DNS record.
     *
     * @return int The new record's id.
     */
    #[OA\Post(
        path: '/domains/{domainId}/dns',
        operationId: 'createDnsRecord',
        summary: 'Create DNS record',
        description: 'Create a DNS record. The required fields depend on `type`.',
        tags: ['dns'],
        security: [['basicAuth' => []]],
        parameters: [new OA\Parameter(ref: '#/components/parameters/DomainId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DNSRecord')),
        responses: [
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(
                response: 201,
                description: 'Successful operation',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'id', type: 'integer', format: 'int32', example: 1, description: 'ID of the created DNS record'),
                ]),
            ),
            new OA\Response(response: 400, description: 'DNS record failed validation'),
            new OA\Response(response: 404, description: 'Domain not found'),
        ],
    )]
    public function create(int $domainId, DnsRecord $record): int
    {
        /** @var array<string, mixed> $result */
        $result = (array) $this->transport->post(\sprintf('/domains/%d/dns', $domainId), $record->toArray());

        return (int) ($result['id'] ?? 0);
    }

    /**
     * Replace a DNS record.
     *
     * This is a full replacement, not a patch: $record must carry every field
     * the record type requires, including the ones you are not changing.
     */
    #[OA\Put(
        path: '/domains/{domainId}/dns/{recordId}',
        operationId: 'replaceDnsRecord',
        summary: 'Replace DNS record',
        description: 'Replace a DNS record. This is a full replacement, not a patch: the body must carry '
            . 'every field the record type requires, including the ones that are not changing.',
        tags: ['dns'],
        security: [['basicAuth' => []]],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/DomainId'),
            new OA\Parameter(ref: '#/components/parameters/RecordId'),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DNSRecord')),
        responses: [
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 204, description: 'Successful operation'),
            new OA\Response(response: 400, description: 'DNS record failed validation'),
            new OA\Response(response: 404, description: 'DNS record does not exist'),
        ],
    )]
    public function update(int $domainId, int $recordId, DnsRecord $record): void
    {
        $this->transport->put(\sprintf('/domains/%d/dns/%d', $domainId, $recordId), $record->toArray());
    }

    /** Delete a DNS record. */
    #[OA\Delete(
        path: '/domains/{domainId}/dns/{recordId}',
        operationId: 'deleteDnsRecord',
        summary: 'Delete DNS record',
        description: 'Delete a DNS record.',
        tags: ['dns'],
        security: [['basicAuth' => []]],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/DomainId'),
            new OA\Parameter(ref: '#/components/parameters/RecordId'),
        ],
        responses: [
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 204, description: 'DNS record is deleted'),
            new OA\Response(response: 404, description: 'DNS record does not exist'),
        ],
    )]
    public function delete(int $domainId, int $recordId): void
    {
        $this->transport->delete(\sprintf('/domains/%d/dns/%d', $domainId, $recordId));
    }
}
