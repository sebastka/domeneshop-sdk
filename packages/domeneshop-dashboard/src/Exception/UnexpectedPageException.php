<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Dashboard\Exception;

/**
 * A page did not contain what this package expects.
 *
 * This is the important failure mode of scraping, and the reason every parser
 * here asserts the structure it relies on. Domeneshop can change the dashboard
 * whenever they like — there is no contract and no versioning — and a parser
 * that quietly returned an empty list when the markup moved would be worse than
 * one that crashed: you would act on an answer that looks authoritative and is
 * simply wrong.
 *
 * Seeing this means the dashboard changed and this package needs updating. It
 * does not mean the data is absent.
 */
final class UnexpectedPageException extends \RuntimeException implements DashboardException
{
    public static function missing(string $what, string $path): self
    {
        return new self(\sprintf(
            'Could not find %s on %s. The dashboard markup has probably changed, so this package '
            . 'cannot read it any more. Do not treat this as "no data" — please report it.',
            $what,
            $path,
        ));
    }

    /**
     * The page has content this package cannot classify — typically a table
     * row that is neither a record it knows nor the "add" row.
     *
     * Skipping it would be the easy thing and the wrong one: an unrecognised
     * row is most likely data.
     */
    public static function unrecognised(string $what, string $path, string $detail = ''): self
    {
        return new self(\sprintf(
            'Found %s on %s that this package does not recognise, so it will not guess at it.%s '
            . 'Please report it, with the page saved by `domeneshop dashboard:fetch` (check it for personal data first).',
            $what,
            $path,
            $detail === '' ? '' : ' ' . $detail,
        ));
    }
}
