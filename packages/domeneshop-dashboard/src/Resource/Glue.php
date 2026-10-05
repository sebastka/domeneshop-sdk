<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Dashboard\Resource;

use Sebastka\Domeneshop\Dashboard\Exception\SessionExpiredException;
use Sebastka\Domeneshop\Dashboard\Exception\UnexpectedPageException;
use Sebastka\Domeneshop\Dashboard\Model\GlueRecord;
use Sebastka\Domeneshop\Dashboard\Parser\GlueParser;

/**
 * The domain's glue records.
 *
 * Fetches the page and hands it to {@see GlueParser}, which documents what it
 * reads and when it refuses.
 */
final class Glue
{
    public function __construct(private readonly Pages $pages)
    {
    }

    /**
     * @return list<GlueRecord>
     *
     * @throws SessionExpiredException when the session cookie is not accepted.
     * @throws UnexpectedPageException when the page is not what the parser expects.
     */
    public function forDomain(int $domainId): array
    {
        return GlueParser::parse(
            $this->pages->fetch($domainId, 'glue'),
            Pages::path($domainId, 'glue'),
        );
    }
}
