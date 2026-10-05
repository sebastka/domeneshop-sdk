<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Dashboard\Exception;

/**
 * The dashboard does not offer this page for this domain.
 *
 * Domeneshop does not answer such a request with an error. It serves the
 * domain's overview page instead, with a 200, as though that were what you
 * asked for. This exception is how that is reported — distinct from
 * {@see UnexpectedPageException}, because nothing has changed and nothing is
 * broken; the page simply does not apply.
 *
 * The case seen so far is DNSSEC: for a domain on Domeneshop's own nameservers,
 * Domeneshop signs the zone and publishes the DS records itself, so there is no
 * DS record editor to show. Whether DNSSEC is on is then read from the
 * nameservers page.
 */
final class PageUnavailableException extends \RuntimeException implements DashboardException
{
    private const HINTS = [
        'dnssec' => ' This happens for domains on Domeneshop\'s own nameservers, where Domeneshop '
            . 'manages the DS records itself; whether DNSSEC is enabled is shown on the nameservers page.',
    ];

    public static function servedOverview(string $edit, string $path): self
    {
        return new self(\sprintf(
            'The dashboard does not offer the "%s" page for this domain: %s served the domain overview instead.%s',
            $edit,
            $path,
            self::HINTS[$edit] ?? '',
        ));
    }
}
