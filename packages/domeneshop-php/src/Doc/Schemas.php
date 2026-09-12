<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Doc;

use OpenApi\Attributes as OA;

/**
 * Reusable object schemas (components/schemas), referenced from operation
 * requests and responses. Kept central so the model classes stay free of doc
 * attributes.
 *
 * The record schemas mirror the API's own `oneOf` structure: a `BaseRecord`
 * with the shared fields, and one variant per type carrying its extras. The
 * SDK models these as a class hierarchy, which is the same shape expressed in
 * PHP — see {@see \Sebastka\Domeneshop\Model\DnsRecord}.
 *
 * Attribute-only holder; no runtime behaviour.
 */
#[OA\Schema(
    schema: 'DomainServices',
    type: 'object',
    description: 'Which Domeneshop services are active for a domain.',
    properties: [
        new OA\Property(property: 'registrar', type: 'boolean', description: 'Whether Domeneshop is the registrar.'),
        new OA\Property(property: 'dns', type: 'boolean', description: 'Whether Domeneshop hosts the DNS. Must be true to manage records.'),
        new OA\Property(property: 'email', type: 'boolean', description: 'Whether the email service is active.'),
        new OA\Property(property: 'webhotel', type: 'string', enum: ['none', 'websmall', 'webmedium', 'weblarge', 'webxlarge']),
    ],
)]
#[OA\Schema(
    schema: 'Domain',
    type: 'object',
    description: 'A domain in the account. Read-only: registration and transfer are not part of the API.',
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int32', example: 1, description: 'The domain\'s numeric id.'),
        new OA\Property(property: 'domain', type: 'string', example: 'example.com'),
        new OA\Property(property: 'expiry_date', type: 'string', format: 'date'),
        new OA\Property(property: 'registered_date', type: 'string', format: 'date'),
        new OA\Property(property: 'renew', type: 'boolean', example: true, description: 'Whether the domain auto-renews.'),
        new OA\Property(property: 'registrant', type: 'string', example: 'Ola Nordmann'),
        new OA\Property(
            property: 'status',
            type: 'string',
            enum: ['active', 'expired', 'deactivated', 'pendingDeleteRestorable'],
            description: 'Domain status.',
        ),
        new OA\Property(
            property: 'nameservers',
            type: 'array',
            minItems: 2,
            maxItems: 6,
            items: new OA\Items(type: 'string'),
            example: ['ns1.hyp.net', 'ns2.hyp.net', 'ns3.hyp.net'],
        ),
        new OA\Property(property: 'services', ref: '#/components/schemas/DomainServices'),
    ],
)]
#[OA\Schema(
    schema: 'BaseRecord',
    type: 'object',
    required: ['host'],
    description: 'The fields every DNS record shares.',
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int32', readOnly: true, example: 1, description: 'The record\'s id. Read-only: it is carried in the URL, never the body.'),
        new OA\Property(property: 'host', type: 'string', example: '@', description: 'The host/subdomain the record applies to; `@` is the zone apex.'),
        new OA\Property(
            property: 'ttl',
            type: 'integer',
            format: 'int32',
            default: 3600,
            minimum: 60,
            maximum: 604800,
            description: 'TTL in seconds. Must be a multiple of 60. Omit to accept the default of 3600.',
        ),
    ],
)]
#[OA\Schema(schema: 'A', description: 'Maps a host to an IPv4 address.', allOf: [
    new OA\Schema(ref: '#/components/schemas/BaseRecord'),
    new OA\Schema(type: 'object', required: ['type', 'data'], properties: [
        new OA\Property(property: 'type', type: 'string', enum: ['A']),
        new OA\Property(property: 'data', type: 'string', format: 'ipv4', example: '203.0.113.10', description: 'IPv4 address.'),
    ]),
])]
#[OA\Schema(schema: 'AAAA', description: 'Maps a host to an IPv6 address.', allOf: [
    new OA\Schema(ref: '#/components/schemas/BaseRecord'),
    new OA\Schema(type: 'object', required: ['type', 'data'], properties: [
        new OA\Property(property: 'type', type: 'string', enum: ['AAAA']),
        new OA\Property(property: 'data', type: 'string', format: 'ipv6', example: '2001:db8::1', description: 'IPv6 address.'),
    ]),
])]
#[OA\Schema(schema: 'CNAME', description: 'Aliases a host to another hostname.', allOf: [
    new OA\Schema(ref: '#/components/schemas/BaseRecord'),
    new OA\Schema(type: 'object', required: ['type', 'data'], properties: [
        new OA\Property(property: 'type', type: 'string', enum: ['CNAME']),
        new OA\Property(property: 'data', type: 'string', format: 'hostname', example: 'www.example.com', description: 'The target hostname.'),
    ]),
])]
#[OA\Schema(schema: 'MX', description: 'Names a mail exchanger for the host, with a preference.', allOf: [
    new OA\Schema(ref: '#/components/schemas/BaseRecord'),
    new OA\Schema(type: 'object', required: ['type', 'data', 'priority'], properties: [
        new OA\Property(property: 'type', type: 'string', enum: ['MX']),
        new OA\Property(property: 'data', type: 'string', format: 'hostname', example: 'mx.example.com', description: 'The target MX host.'),
        new OA\Property(property: 'priority', type: 'integer', format: 'int32', example: 10, description: 'Preference; lower values are usually tried first, though nothing guarantees it.'),
    ]),
])]
#[OA\Schema(schema: 'SRV', description: 'Advertises the host and port of a service. The `host` follows the `_service._protocol` convention.', allOf: [
    new OA\Schema(ref: '#/components/schemas/BaseRecord'),
    new OA\Schema(type: 'object', required: ['type', 'data', 'priority', 'weight', 'port'], properties: [
        new OA\Property(property: 'type', type: 'string', enum: ['SRV']),
        new OA\Property(property: 'data', type: 'string', format: 'hostname', example: 'sip.example.com', description: 'The target hostname.'),
        new OA\Property(property: 'priority', type: 'integer', format: 'int32', example: 10, description: 'Preference; lower values are tried first.'),
        new OA\Property(property: 'weight', type: 'integer', format: 'int32', example: 100, description: 'Relative weight among records sharing a priority.'),
        new OA\Property(property: 'port', type: 'integer', format: 'int32', example: 5060, description: 'The port the service listens on.'),
    ]),
])]
#[OA\Schema(schema: 'TLSA', description: "Pins a certificate or public key for DANE. The `host` follows the `_port._protocol` convention.\n\n"
    . 'Present in the official OpenAPI definition but absent from the published prose reference.', allOf: [
    new OA\Schema(ref: '#/components/schemas/BaseRecord'),
    new OA\Schema(type: 'object', required: ['type', 'data', 'usage', 'selector', 'dtype'], properties: [
        new OA\Property(property: 'type', type: 'string', enum: ['TLSA']),
        new OA\Property(property: 'data', type: 'string', example: '7FF8B87BB269715FEF08A0F4F0033D7A2F3B680470A30E878CEB2D3E244AF3EA', description: 'The hash. Its length follows `dtype`: 64 hex characters for SHA-256, 128 for SHA-512.'),
        new OA\Property(property: 'usage', type: 'integer', format: 'int32', enum: [0, 1, 2, 3], example: 3, description: 'How the hash should be interpreted: 0 PKIX-TA, 1 PKIX-EE, 2 DANE-TA, 3 DANE-EE.'),
        new OA\Property(property: 'selector', type: 'integer', format: 'int32', enum: [0, 1], example: 1, description: 'What the hash was taken from: 0 the full certificate, 1 the subject public key.'),
        new OA\Property(property: 'dtype', type: 'integer', format: 'int32', enum: [0, 1, 2], example: 1, description: 'Matching type: 0 exact match, 1 SHA-256, 2 SHA-512.'),
    ]),
])]
#[OA\Schema(schema: 'TXT', description: 'Freeform text; commonly SPF, DKIM and domain-verification tokens.', allOf: [
    new OA\Schema(ref: '#/components/schemas/BaseRecord'),
    new OA\Schema(type: 'object', required: ['type', 'data'], properties: [
        new OA\Property(property: 'type', type: 'string', enum: ['TXT']),
        new OA\Property(property: 'data', type: 'string', example: 'v=spf1 include:_spf.domeneshop.no ~all', description: 'Freeform text.'),
    ]),
])]
#[OA\Schema(
    schema: 'DNSRecord',
    description: 'Any DNS record. The variant is selected by `type`.',
    oneOf: [
        new OA\Schema(ref: '#/components/schemas/A'),
        new OA\Schema(ref: '#/components/schemas/AAAA'),
        new OA\Schema(ref: '#/components/schemas/CNAME'),
        new OA\Schema(ref: '#/components/schemas/MX'),
        new OA\Schema(ref: '#/components/schemas/SRV'),
        new OA\Schema(ref: '#/components/schemas/TLSA'),
        new OA\Schema(ref: '#/components/schemas/TXT'),
    ],
)]
#[OA\Schema(
    schema: 'HTTPForward',
    type: 'object',
    description: 'A subdomain that redirects to a URL.',
    properties: [
        new OA\Property(
            property: 'host',
            type: 'string',
            example: '@',
            description: "The subdomain this forward applies to, without the domain part.\n\n"
                . 'For instance, `www` in the context of `example.com` signifies a forward for `www.example.com`. '
                . 'Use `@` for the apex.',
        ),
        new OA\Property(property: 'frame', type: 'boolean', example: false, description: 'Serve the target inside an iframe instead of redirecting. Not recommended.'),
        new OA\Property(property: 'url', type: 'string', example: 'https://www.example.com', description: 'The URL to forward to. Must include a scheme, e.g. `https://`.'),
    ],
)]
#[OA\Schema(
    schema: 'Invoice',
    type: 'object',
    description: 'An invoice or credit note. Only the past three years are available.',
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int32', example: 1, description: 'Invoice number.'),
        new OA\Property(
            property: 'type',
            type: 'string',
            enum: ['invoice', 'credit_node'],
            description: 'Note the value `credit_node`: the API really does spell "credit note" that way, and the client mirrors it verbatim.',
        ),
        new OA\Property(property: 'amount', type: 'integer', format: 'int32', example: 120, description: 'The total, in the currency\'s major unit.'),
        new OA\Property(property: 'currency', type: 'string', enum: ['NOK', 'SEK', 'DKK', 'GBP', 'USD']),
        new OA\Property(property: 'due_date', type: 'string', format: 'date', description: 'Only present for type `invoice`.'),
        new OA\Property(property: 'issued_date', type: 'string', format: 'date'),
        new OA\Property(property: 'paid_date', type: 'string', format: 'date', description: 'Only present once the invoice has status `paid`.'),
        new OA\Property(property: 'status', type: 'string', enum: ['unpaid', 'paid', 'settled'], example: 'paid', description: '`settled` applies only to credit notes.'),
        new OA\Property(property: 'url', type: 'string', format: 'uri', example: 'https://www.domeneshop.no/invoice?nr=1&code='),
    ],
)]
final class Schemas
{
}
