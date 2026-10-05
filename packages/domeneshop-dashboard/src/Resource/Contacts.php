<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Dashboard\Resource;

use Sebastka\Domeneshop\Dashboard\Exception\SessionExpiredException;
use Sebastka\Domeneshop\Dashboard\Exception\UnexpectedPageException;
use Sebastka\Domeneshop\Dashboard\Model\Contacts as ContactsModel;
use Sebastka\Domeneshop\Dashboard\Parser\ContactsParser;

/**
 * The domain's contacts, and the account's billing contact.
 *
 * Fetches the page and hands it to {@see ContactsParser}, which documents what it
 * reads and when it refuses.
 */
final class Contacts
{
    public function __construct(private readonly Pages $pages)
    {
    }

    /**
     * @throws SessionExpiredException when the session cookie is not accepted.
     * @throws UnexpectedPageException when the page is not what the parser expects.
     */
    public function forDomain(int $domainId): ContactsModel
    {
        return ContactsParser::parse(
            $this->pages->fetch($domainId, 'contacts'),
            Pages::path($domainId, 'contacts'),
        );
    }
}
