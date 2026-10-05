<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Dashboard\Model;

/**
 * One contact role on a domain — owner, administrative or technical.
 *
 * Which fields exist depends on the TLD's registry: a `.no` owner has a handle
 * and an organisation number but no fax, while `.com` has a fax field and
 * separate admin and tech contacts. So every field is nullable, and null means
 * the page had no such field or left it empty. Anything the page sends for the
 * role that is not modelled here is kept verbatim in {@see self::$extra} rather
 * than dropped, so a TLD with a different schema loses nothing.
 */
final class Contact
{
    /**
     * @param string|null           $name    The name as the dashboard displays it (owner only). Set even
     *                                       where the name fields are empty, as they are for some TLDs.
     * @param string|null           $country ISO 3166-1 alpha-2 code, lower case, e.g. `no`.
     * @param array<string, string> $extra   Other fields for this role, keyed by the dashboard's
     *                                       field name without the role prefix.
     */
    public function __construct(
        public readonly ?string $handle = null,
        public readonly ?string $name = null,
        public readonly ?string $organization = null,
        public readonly ?string $orgNumber = null,
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly ?string $address = null,
        public readonly ?string $zip = null,
        public readonly ?string $city = null,
        public readonly ?string $country = null,
        public readonly ?string $phone = null,
        public readonly ?string $fax = null,
        public readonly ?string $email = null,
        public readonly array $extra = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'handle' => $this->handle,
            'name' => $this->name,
            'organization' => $this->organization,
            'org_number' => $this->orgNumber,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'address' => $this->address,
            'zip' => $this->zip,
            'city' => $this->city,
            'country' => $this->country,
            'phone' => $this->phone,
            'fax' => $this->fax,
            'email' => $this->email,
            'extra' => $this->extra,
        ];
    }
}
