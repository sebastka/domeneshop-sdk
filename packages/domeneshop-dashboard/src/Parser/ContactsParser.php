<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Dashboard\Parser;

use Sebastka\Domeneshop\Dashboard\Exception\UnexpectedPageException;
use Sebastka\Domeneshop\Dashboard\Html\Page;
use Sebastka\Domeneshop\Dashboard\Model\BillingContact;
use Sebastka\Domeneshop\Dashboard\Model\Contact;
use Sebastka\Domeneshop\Dashboard\Model\Contacts;

/**
 * Reads `/admin?id=…&edit=contacts`.
 *
 * ## The schema depends on the TLD
 *
 * Each registry decides which contacts a domain has and what they hold, and the
 * holder's type matters too. Seen so far:
 *
 *  - `.no`, organisation holder: owner only, with handle and organisation number.
 *  - `.no`, private-person holder: owner with a Norid PID (sent as `o_vatno`,
 *    displayed as "PID"), plus a technical contact.
 *  - `.com`, `.org`, `.info`, `.app`, `.fr`: owner, admin and tech, with fax
 *    fields, differing in which WHOIS privacy options they offer.
 *
 * Other TLDs will differ again.
 *
 * So this does not parse a fixed layout. It relies on the one convention that
 * holds across all of them: form fields are named with a role prefix — `o_`
 * owner, `a_` admin, `t_` tech — and a field name. Known field names map to
 * {@see Contact} properties; everything else is kept in `extra` under the
 * dashboard's own name — `vatno` included, since its content is a PID rather
 * than the VAT number the name suggests, and a typed property would have to
 * pick one meaning. What must be
 * there is an owner and the billing contact; the rest is taken as found.
 *
 * The billing contact is not a form: it is label/value text under a
 * "Betalingskontakt" heading, read by label.
 */
final class ContactsParser
{
    private const ROLES = ['o' => 'owner', 'a' => 'admin', 't' => 'tech'];

    /** Dashboard field name => Contact constructor argument. */
    private const FIELDS = [
        'handle' => 'handle',
        'organization' => 'organization',
        'orgno' => 'orgNumber',
        'firstname' => 'firstName',
        'lastname' => 'lastName',
        'address' => 'address',
        'zip' => 'zip',
        'city' => 'city',
        'country' => 'country',
        'phone' => 'phone',
        'fax' => 'fax',
        'email' => 'email',
    ];

    /** Form controls that are UI, not data: the "Kopier data fra" pickers. */
    private const IGNORED = ['type'];

    private const SECTIONS = [
        'Innehaver' => 'owner',
        'Administrativ kontakt' => 'admin',
        'Teknisk kontakt' => 'tech',
        'Betalingskontakt' => 'billing',
    ];

    /** Billing label => BillingContact constructor argument. */
    private const BILLING = [
        'Fornavn' => 'firstName',
        'Etternavn' => 'lastName',
        'Postadresse' => 'address',
        'Postnummer' => 'zip',
        'Sted' => 'city',
        'Land' => 'country',
        'Telefon' => 'phone',
        'Epost' => 'email',
    ];

    /**
     * @throws UnexpectedPageException when there is no owner or no billing contact.
     */
    public static function parse(string $html, string $path): Contacts
    {
        $page = Page::parse($html, $path, 'contacts');
        $table = $page->one('.AdminPanel table.Admin', 'the contact table');

        [$ownerName, $billing] = self::readRows($page, $table);
        $fields = self::readFields($page);

        if (! isset($fields['o'])) {
            throw UnexpectedPageException::missing('the owner contact fields (o_*)', $path);
        }

        if ($billing === []) {
            throw UnexpectedPageException::missing('the billing contact (Betalingskontakt)', $path);
        }

        return new Contacts(
            owner: self::contact($fields['o'], $ownerName),
            admin: isset($fields['a']) ? self::contact($fields['a']) : null,
            tech: isset($fields['t']) ? self::contact($fields['t']) : null,
            billing: new BillingContact(...array_map(
                static fn (string $label): ?string => $billing[$label] ?? null,
                array_flip(self::BILLING),
            )),
            hideEmail: $page->checkbox('hide_email'),
            hidePersonalData: $page->checkbox('hide_personaldata'),
        );
    }

    /**
     * Walk the table's rows, tracking which section each belongs to.
     *
     * @return array{?string, array<string, string>} The owner's display name, and billing values by label.
     */
    private static function readRows(Page $page, \Dom\Element $table): array
    {
        $section = null;
        $ownerName = null;
        $billing = [];

        foreach ($page->all('tr', $table) as $row) {
            $heading = $row->querySelector('td[colspan] > b');
            if ($heading !== null && str_ends_with(Page::clean($heading->textContent), ':')) {
                $section = self::SECTIONS[rtrim(Page::clean($heading->textContent), ':')] ?? 'other';

                continue;
            }

            $left = $row->querySelector('td.AdminLeft');
            $right = $row->querySelector('td.AdminRight');
            if ($left === null || $right === null || $right->querySelector('input, select') !== null) {
                continue;
            }

            $label = self::label($left->textContent);
            $value = Page::text($right);

            // The owner's first read-only row is the name — labelled "Navn" or
            // "Privatperson" depending on TLD and owner type, so go by position.
            if ($section === 'owner' && $ownerName === null && $label !== 'Organisasjonsnummer') {
                $ownerName = $value === '' ? null : $value;
            }

            if ($section === 'billing' && isset(self::BILLING[$label])) {
                $billing[$label] = $value;
            }
        }

        return [$ownerName, $billing];
    }

    /**
     * Every role-prefixed control on the page, grouped by role.
     *
     * @return array<string, array<string, string>> role prefix => field => value
     */
    private static function readFields(Page $page): array
    {
        $fields = [];

        foreach ($page->all('input[name], select[name]') as $control) {
            if (! preg_match('/^([oat])_([a-z_]+)$/', (string) $control->getAttribute('name'), $m)
                || \in_array($m[2], self::IGNORED, true)) {
                continue;
            }

            // Names can repeat: a `.no` page for a private-person owner carries
            // o_firstname, o_lastname and o_name twice, filled and then empty.
            // The first non-empty value is the data.
            $value = trim((string) Page::valueOf($control));
            if (($fields[$m[1]][$m[2]] ?? '') === '') {
                $fields[$m[1]][$m[2]] = $value;
            }
        }

        return $fields;
    }

    /** @param array<string, string> $fields */
    private static function contact(array $fields, ?string $name = null): Contact
    {
        $known = [];
        $extra = [];

        foreach ($fields as $field => $value) {
            if (isset(self::FIELDS[$field])) {
                $known[self::FIELDS[$field]] = $value === '' ? null : $value;
            } else {
                $extra[$field] = $value;
            }
        }

        return new Contact(...$known, name: $name, extra: $extra);
    }

    private static function label(string $text): string
    {
        return trim((string) preg_replace('/\s*\(valgfritt\)\s*$/u', '', rtrim(Page::clean($text), ':')));
    }
}
