<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Dashboard\Model;

/**
 * The account's billing contact, as shown at the bottom of a domain's contact
 * page.
 *
 * It belongs to the account rather than the domain — the dashboard edits it
 * under "Konto-informasjon" — and is displayed as text, not as form fields, so
 * the country is the displayed name ("Norge") rather than a code.
 */
final class BillingContact
{
    public function __construct(
        public readonly ?string $firstName,
        public readonly ?string $lastName,
        public readonly ?string $address,
        public readonly ?string $zip,
        public readonly ?string $city,
        public readonly ?string $country,
        public readonly ?string $phone,
        public readonly ?string $email,
    ) {
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'address' => $this->address,
            'zip' => $this->zip,
            'city' => $this->city,
            'country' => $this->country,
            'phone' => $this->phone,
            'email' => $this->email,
        ];
    }
}
