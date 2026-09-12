<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Doc;

use OpenApi\Attributes as OA;

/**
 * Document-level OpenAPI metadata: info, server, security scheme and tags.
 *
 * This class carries no runtime behaviour — it only hosts attributes that
 * `bin/generate-openapi.php` reads. swagger-php is a dev dependency, so these
 * attributes are inert in production.
 *
 * Why we generate our own spec rather than vendoring Domeneshop's: theirs is
 * incomplete and in places wrong (see the README's "Where we differ from the
 * published docs"). Generating from the same attributes that sit on the methods
 * doing the work means the spec cannot drift from the client.
 */
#[OA\Info(
    version: '0.1.0',
    title: 'Domeneshop API',
    description: "Reference for the Domeneshop API (v0), generated from the `sebastka/domeneshop-php` client.\n\n"
        . "This is an **unofficial** document. It is generated from the attributes on the client's own "
        . "resource methods, so it describes exactly what the client sends and expects. It corrects a "
        . "handful of omissions and errors in the published documentation — most visibly the `TLSA` "
        . "record type, which the official OpenAPI definition includes but the prose reference omits.\n\n"
        . "Credentials are generated at https://www.domeneshop.no/admin?view=api. The API authenticates "
        . "with HTTP Basic: the token is the username, the secret is the password.",
    contact: new OA\Contact(name: 'Domeneshop kundeservice', email: 'kundeservice@domeneshop.no'),
    license: new OA\License(name: 'MIT', identifier: 'MIT'),
)]
#[OA\ExternalDocumentation(description: 'Official Domeneshop API documentation', url: 'https://api.domeneshop.no/docs/')]
#[OA\Server(url: 'https://api.domeneshop.no/v0', description: 'Domeneshop API v0')]
#[OA\SecurityScheme(
    securityScheme: 'basicAuth',
    type: 'http',
    scheme: 'basic',
    description: "HTTP Basic authentication: the API **token** is the username and the API **secret** "
        . "is the password. Generate a pair at https://www.domeneshop.no/admin?view=api",
)]
#[OA\Tag(name: 'domains', description: 'Read the domains in the account')]
#[OA\Tag(name: 'dns', description: 'Manage a domain\'s DNS records')]
#[OA\Tag(name: 'forwards', description: 'Manage a domain\'s HTTP forwards')]
#[OA\Tag(name: 'invoices', description: 'Read invoices and credit notes')]
#[OA\Tag(name: 'ddns', description: 'Dynamic DNS updates')]
final class OpenApiDefinition
{
}
