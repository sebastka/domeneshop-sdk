<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Dashboard\Resource;

use Sebastka\Domeneshop\Dashboard\Exception\SessionExpiredException;
use Sebastka\Domeneshop\Dashboard\Http\Transport;

/**
 * The dashboard pages holding data the API does not expose, returned as raw
 * HTML.
 *
 * The typed accessors — `contacts()->forDomain()` and friends — fetch through
 * this and parse the result. Parsers are written against saved copies of the
 * real pages, kept in `tests/Fixture/Page`, so that a dashboard change shows up
 * as a failing test rather than as wrong data in production.
 *
 * Use this directly to capture a page for a new fixture or a bug report — see
 * `domeneshop dashboard:fetch`. The HTML contains personal data and per-page
 * CSRF tokens; redact both before sharing it.
 */
final class Pages
{
    /**
     * The `edit` values the dashboard uses, mapped to the names this package
     * calls them. Keeping the mapping in one place means the CLI, the fixtures
     * and the future parsers cannot disagree about what a page is called.
     */
    public const PAGES = [
        'contacts' => 'contacts',
        'nameservers' => 'ns',
        'dnssec' => 'dnssec',
        'glue' => 'glue',
    ];

    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * Fetch one dashboard page for a domain.
     *
     * @param string $page One of the keys of {@see self::PAGES}.
     *
     * @throws \InvalidArgumentException when $page is not one we know.
     * @throws SessionExpiredException   when the session cookie is not accepted.
     */
    public function fetch(int $domainId, string $page): string
    {
        if (! isset(self::PAGES[$page])) {
            throw new \InvalidArgumentException(\sprintf(
                'Unknown dashboard page "%s". Available: %s.',
                $page,
                implode(', ', array_keys(self::PAGES)),
            ));
        }

        return $this->transport->get('/admin', [
            'id' => $domainId,
            'edit' => self::PAGES[$page],
        ]);
    }

    /** The page's path, as used in error messages. Carries no secrets. */
    public static function path(int $domainId, string $page): string
    {
        return \sprintf('/admin?id=%d&edit=%s', $domainId, self::PAGES[$page] ?? $page);
    }

    /** @return list<string> The page names {@see self::fetch()} accepts. */
    public static function names(): array
    {
        return array_keys(self::PAGES);
    }
}
