<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Dashboard\Parser;

use Sebastka\Domeneshop\Dashboard\Exception\UnexpectedPageException;
use Sebastka\Domeneshop\Dashboard\Html\Page;
use Sebastka\Domeneshop\Dashboard\Model\Nameservers;

/**
 * Reads `/admin?id=…&edit=ns`: six nameserver inputs and a DNSSEC checkbox.
 */
final class NameserversParser
{
    private const SLOTS = 6;

    /**
     * @throws UnexpectedPageException when the inputs or the checkbox are missing.
     */
    public static function parse(string $html, string $path): Nameservers
    {
        $page = Page::parse($html, $path, 'ns');

        $hosts = [];
        for ($slot = 1; $slot <= self::SLOTS; ++$slot) {
            $value = $page->control('ns' . $slot);

            // The first two slots are mandatory on the form; if even those are
            // gone, the page is not the one this parser knows.
            if ($value === null && $slot <= 2) {
                throw UnexpectedPageException::missing(\sprintf('the ns%d input', $slot), $path);
            }

            if ($value !== null && trim($value) !== '') {
                $hosts[] = trim($value);
            }
        }

        return new Nameservers(
            hosts: $hosts,
            dnssec: $page->checkbox('dnssec') ?? throw UnexpectedPageException::missing('the DNSSEC checkbox', $path),
        );
    }
}
