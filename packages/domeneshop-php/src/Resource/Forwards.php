<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Resource;

use Sebastka\Domeneshop\Exception\ConflictException;
use Sebastka\Domeneshop\Exception\NotFoundException;
use Sebastka\Domeneshop\Exception\PreconditionFailedException;
use Sebastka\Domeneshop\Http\Transport;
use OpenApi\Attributes as OA;
use Sebastka\Domeneshop\Model\HttpForward;

/**
 * HTTP forwards for one domain (`/domains/{domainId}/forwards/`).
 *
 * Forwards are addressed by host, not by a numeric id. The apex is `@`, which
 * has to survive URL encoding intact — hence the explicit encoding below.
 *
 * ## The API's per-host endpoint does not work
 *
 * `GET`, `PUT` and `DELETE` on `/domains/{domainId}/forwards/{host}` answer 404
 * for every host — including one the collection endpoint has just listed, and
 * including a forward created seconds earlier through this very client. This
 * was verified against the live API on two separate domains, with plain curl as
 * well as through the SDK, and it does not resolve with time.
 *
 * The consequences are unavoidable and worth stating plainly:
 *
 *   - {@see self::get()} works, because it is implemented against the
 *     collection endpoint instead. See its note.
 *   - {@see self::update()} and {@see self::delete()} **cannot work**. The API
 *     exposes no other route for either, so a forward created through the API
 *     can currently only be changed or removed in the Domeneshop web interface.
 *
 * These two methods are kept, and still send exactly what the documentation
 * specifies, so they will start working the moment the endpoint is fixed.
 */
final class Forwards
{
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * List a domain's HTTP forwards.
     *
     * @return list<HttpForward>
     */
    #[OA\Get(
        path: '/domains/{domainId}/forwards/',
        operationId: 'listForwards',
        summary: 'List HTTP forwards',
        description: "List a domain's HTTP forwards.\n\nNote the trailing slash: the API 404s without it.",
        tags: ['forwards'],
        security: [['basicAuth' => []]],
        parameters: [new OA\Parameter(ref: '#/components/parameters/DomainId')],
        responses: [
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 200, description: 'Successful operation', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/HTTPForward'))),
            new OA\Response(response: 404, description: 'Domain not found'),
        ],
    )]
    public function list(int $domainId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = (array) $this->transport->get(\sprintf('/domains/%d/forwards/', $domainId));

        return array_values(array_map(HttpForward::fromArray(...), $rows));
    }

    /**
     * Fetch a single forward by host (`@` for the apex).
     *
     * Implemented by listing and filtering rather than by calling the API's
     * per-host endpoint, which answers 404 for every host (see the class note).
     * The collection endpoint works correctly, so this returns the right answer;
     * the cost is one full listing per lookup, which for a domain's handful of
     * forwards is immaterial.
     *
     * @throws NotFoundException when the domain has no forward on that host.
     */
    #[OA\Get(
        path: '/domains/{domainId}/forwards/{host}',
        operationId: 'getForward',
        summary: 'Get HTTP forward',
        description: 'Fetch a single forward by host.',
        tags: ['forwards'],
        security: [['basicAuth' => []]],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/DomainId'),
            new OA\Parameter(ref: '#/components/parameters/ForwardHost'),
        ],
        responses: [
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 200, description: 'Successful operation', content: new OA\JsonContent(ref: '#/components/schemas/HTTPForward')),
            new OA\Response(response: 404, description: 'Forward or domain not found'),
        ],
    )]
    public function get(int $domainId, string $host): HttpForward
    {
        foreach ($this->list($domainId) as $forward) {
            if ($forward->host === $host) {
                return $forward;
            }
        }

        throw new NotFoundException(
            404,
            \sprintf('No forward on host "%s".', $host),
            \sprintf('GET /domains/%d/forwards/', $domainId),
        );
    }

    /**
     * Create a forward.
     *
     * @throws ConflictException when the host already has a forward, or an
     *                           A/AAAA/ANAME/CNAME record that would collide.
     */
    #[OA\Post(
        path: '/domains/{domainId}/forwards/',
        operationId: 'createForward',
        summary: 'Add HTTP forward',
        description: "Create a forwarding for the specified domain, to a given URL.\n\n"
            . 'The forward must not collide with any existing forwarding or DNS record of types '
            . '`A`, `AAAA`, `ANAME` or `CNAME`.',
        tags: ['forwards'],
        security: [['basicAuth' => []]],
        parameters: [new OA\Parameter(ref: '#/components/parameters/DomainId')],
        // The published spec omits this body entirely; it is required in practice.
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HTTPForward')),
        responses: [
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 201, description: 'Successful operation'),
            new OA\Response(response: 400, description: 'Invalid forward'),
            // The published document omits this, though the path takes a domainId
            // that may not exist.
            new OA\Response(response: 404, description: 'Domain not found'),
            new OA\Response(response: 409, description: 'The forward collides with an existing forwarding or DNS record'),
        ],
    )]
    public function create(int $domainId, HttpForward $forward): void
    {
        $this->transport->post(\sprintf('/domains/%d/forwards/', $domainId), $forward->toArray());
    }

    /**
     * Update the forward on $host.
     *
     * @param HttpForward $forward Its `host` must equal $host — the API rejects
     *                             a rename with 412, since the host is the identity.
     *
     * @return HttpForward The forward as the API stored it.
     *
     * @throws PreconditionFailedException when $forward->host differs from $host.
     */
    #[OA\Put(
        path: '/domains/{domainId}/forwards/{host}',
        operationId: 'replaceForward',
        summary: 'Update HTTP forward',
        description: "Change where a forward points.\n\nThe body's `host` must match the `host` in the path: "
            . 'the host is the forward\'s identity, and the API rejects a rename with 412. To move a forward, '
            . 'delete it and create a new one.',
        tags: ['forwards'],
        security: [['basicAuth' => []]],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/DomainId'),
            new OA\Parameter(ref: '#/components/parameters/ForwardHost'),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HTTPForward')),
        responses: [
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 200, description: 'Successful operation', content: new OA\JsonContent(ref: '#/components/schemas/HTTPForward')),
            new OA\Response(response: 400, description: 'Invalid forward'),
            new OA\Response(response: 404, description: 'Forward or domain not found'),
            new OA\Response(response: 412, description: 'Forward host was modified'),
        ],
    )]
    public function update(int $domainId, string $host, HttpForward $forward): HttpForward
    {
        /** @var array<string, mixed> $data */
        $data = (array) $this->transport->put($this->hostPath($domainId, $host), $forward->toArray());

        // The API documents a 200 with the stored forward, but tolerate an
        // empty body by echoing back what we sent.
        return $data === [] ? $forward : HttpForward::fromArray($data);
    }

    /** Delete the forward on $host. */
    #[OA\Delete(
        path: '/domains/{domainId}/forwards/{host}',
        operationId: 'deleteForward',
        summary: 'Delete HTTP forward',
        description: 'Delete the forward on this host.',
        tags: ['forwards'],
        security: [['basicAuth' => []]],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/DomainId'),
            new OA\Parameter(ref: '#/components/parameters/ForwardHost'),
        ],
        responses: [
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 204, description: 'Forward is deleted'),
            new OA\Response(response: 404, description: 'Forward or domain not found'),
        ],
    )]
    public function delete(int $domainId, string $host): void
    {
        $this->transport->delete($this->hostPath($domainId, $host));
    }

    private function hostPath(int $domainId, string $host): string
    {
        return \sprintf('/domains/%d/forwards/%s', $domainId, rawurlencode($host));
    }
}
