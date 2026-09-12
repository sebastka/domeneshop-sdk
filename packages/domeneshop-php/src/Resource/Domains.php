<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Resource;

use Sebastka\Domeneshop\Exception\NotFoundException;
use Sebastka\Domeneshop\Http\Transport;
use OpenApi\Attributes as OA;
use Sebastka\Domeneshop\Model\Domain;

/**
 * Read access to the domains in the account (`/domains`).
 *
 * The #[OA\*] attributes are the single source of truth for the generated
 * OpenAPI document (see bin/generate-openapi.php), so the spec cannot drift
 * from the client. They are inert at runtime.
 */
final class Domains
{
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * List the domains in the account.
     *
     * @param string|null $domain Substring filter: only domains whose name
     *                            contains this string are returned.
     *
     * @return list<Domain>
     */
    #[OA\Get(
        path: '/domains',
        operationId: 'listDomains',
        summary: 'List domains',
        description: 'List the domains in the account.',
        tags: ['domains'],
        security: [['basicAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'domain', in: 'query', required: false, description: 'Only return domains whose `domain` field includes this string.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Domain'))),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
        ],
    )]
    public function list(?string $domain = null): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = (array) $this->transport->get('/domains', ['domain' => $domain]);

        return array_values(array_map(Domain::fromArray(...), $rows));
    }

    /**
     * Fetch a single domain by id.
     *
     * @throws NotFoundException when no domain has that id.
     */
    #[OA\Get(
        path: '/domains/{domainId}',
        operationId: 'getDomain',
        summary: 'Get domain',
        description: 'Fetch a single domain by id.',
        tags: ['domains'],
        security: [['basicAuth' => []]],
        parameters: [new OA\Parameter(ref: '#/components/parameters/DomainId')],
        responses: [
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 200, description: 'Successful operation', content: new OA\JsonContent(ref: '#/components/schemas/Domain')),
            new OA\Response(response: 404, description: 'Domain not found'),
        ],
    )]
    public function get(int $domainId): Domain
    {
        /** @var array<string, mixed> $data */
        $data = (array) $this->transport->get(\sprintf('/domains/%d', $domainId));

        return Domain::fromArray($data);
    }

    /**
     * Find a domain by its exact name, or null if the account has no such domain.
     *
     * The API only offers a substring filter, so this narrows server-side and
     * then matches exactly — `example.com` will not return `myexample.com`.
     */
    public function findByName(string $domain): ?Domain
    {
        foreach ($this->list($domain) as $candidate) {
            if (strcasecmp($candidate->domain, $domain) === 0) {
                return $candidate;
            }
        }

        return null;
    }
}
