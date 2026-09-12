<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Doc;

use OpenApi\Attributes as OA;

/**
 * Reusable responses (components/responses), referenced from operations via
 * `#/components/responses/<name>`.
 *
 * Every endpoint needs credentials, so every endpoint can answer 401. Defining
 * it once here and referencing it means the answer cannot be right on some
 * operations and missing on others — which is exactly the state the published
 * document is in, where no operation documents it at all.
 *
 * Status codes whose meaning varies by operation — 404 in particular, which may
 * mean a missing domain, record, forward or invoice — stay inline on the
 * operation, where they can say which.
 *
 * Attribute-only holder; no runtime behaviour.
 */
#[OA\Response(
    response: 'Forbidden',
    description: 'The domain is not in this account. The API answers 403 for any domain id you do not own, '
        . 'including ids that do not exist at all — it never distinguishes "no such domain" from "not yours", '
        . 'so the endpoint cannot be used to enumerate the register. Verified live; the published document '
        . 'says 404 here.',
)]
#[OA\Response(
    response: 'Unauthorized',
    description: 'Missing, malformed or rejected credentials. The API uses HTTP Basic: '
        . 'the token is the username and the secret is the password.',
)]
final class Responses
{
}
