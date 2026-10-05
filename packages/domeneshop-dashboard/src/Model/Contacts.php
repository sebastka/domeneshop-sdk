<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Dashboard\Model;

/**
 * Everything on a domain's contact page.
 *
 * Only the owner is universal. Registries that model admin and tech contacts
 * get them; `.no` does not, and they are null there.
 */
final class Contacts
{
    /**
     * @param bool|null $hideEmail        "Skjul epost-adresse i WHOIS". Null when the TLD offers no such option.
     * @param bool|null $hidePersonalData "Skjul alle personopplysninger i WHOIS". Null when not offered.
     */
    public function __construct(
        public readonly Contact $owner,
        public readonly ?Contact $admin,
        public readonly ?Contact $tech,
        public readonly BillingContact $billing,
        public readonly ?bool $hideEmail,
        public readonly ?bool $hidePersonalData,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'owner' => $this->owner->toArray(),
            'admin' => $this->admin?->toArray(),
            'tech' => $this->tech?->toArray(),
            'billing' => $this->billing->toArray(),
            'hide_email' => $this->hideEmail,
            'hide_personal_data' => $this->hidePersonalData,
        ];
    }
}
