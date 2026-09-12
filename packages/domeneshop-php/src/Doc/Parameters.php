<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Doc;

use OpenApi\Attributes as OA;

/**
 * Reusable parameters (components/parameters), referenced from operations via
 * `#/components/parameters/<name>`.
 *
 * Attribute-only holder; no runtime behaviour.
 */
#[OA\Parameter(
    parameter: 'DomainId',
    name: 'domainId',
    in: 'path',
    required: true,
    description: 'The numeric id of the domain, as returned by `GET /domains`.',
    schema: new OA\Schema(type: 'integer', format: 'int32', example: 1),
)]
#[OA\Parameter(
    parameter: 'RecordId',
    name: 'recordId',
    in: 'path',
    required: true,
    description: 'The numeric id of the DNS record.',
    schema: new OA\Schema(type: 'integer', format: 'int32', example: 1),
)]
#[OA\Parameter(
    parameter: 'ForwardHost',
    name: 'host',
    in: 'path',
    required: true,
    description: "The forward's host/subdomain, without the domain part. Use `@` for the zone apex "
        . '(URL-encoded as `%40`). The host is the forward\'s identity — it cannot be changed in place.',
    schema: new OA\Schema(type: 'string', example: '@'),
)]
#[OA\Parameter(
    parameter: 'InvoiceId',
    name: 'invoiceId',
    in: 'path',
    required: true,
    description: 'The invoice number.',
    schema: new OA\Schema(type: 'integer', format: 'int32', example: 1),
)]
final class Parameters
{
}
