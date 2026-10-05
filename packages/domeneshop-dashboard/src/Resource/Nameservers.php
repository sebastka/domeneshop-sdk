<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Dashboard\Resource;

use Sebastka\Domeneshop\Dashboard\Exception\SessionExpiredException;
use Sebastka\Domeneshop\Dashboard\Exception\UnexpectedPageException;
use Sebastka\Domeneshop\Dashboard\Model\Nameservers as NameserversModel;
use Sebastka\Domeneshop\Dashboard\Parser\NameserversParser;

/**
 * The domain's nameservers and DNSSEC flag.
 *
 * Fetches the page and hands it to {@see NameserversParser}, which documents what it
 * reads and when it refuses.
 */
final class Nameservers
{
    public function __construct(private readonly Pages $pages)
    {
    }

    /**
     * @throws SessionExpiredException when the session cookie is not accepted.
     * @throws UnexpectedPageException when the page is not what the parser expects.
     */
    public function forDomain(int $domainId): NameserversModel
    {
        return NameserversParser::parse(
            $this->pages->fetch($domainId, 'nameservers'),
            Pages::path($domainId, 'nameservers'),
        );
    }
}
