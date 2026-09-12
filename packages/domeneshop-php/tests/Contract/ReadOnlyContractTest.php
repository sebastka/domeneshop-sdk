<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Tests\Contract;

use PHPUnit\Framework\Attributes\Group;
use Sebastka\Domeneshop\Exception\ForbiddenException;
use Sebastka\Domeneshop\Exception\NotFoundException;
use Sebastka\Domeneshop\Model\Domain;
use Sebastka\Domeneshop\Model\DomainStatus;
use Sebastka\Domeneshop\Model\InvoiceStatus;

/**
 * Read-only checks against the live API. These need credentials but change
 * nothing, so they are safe to run against a production account.
 */
#[Group('contract')]
final class ReadOnlyContractTest extends ContractTestCase
{
    public function testListDomainsReturnsModelledDomains(): void
    {
        $domains = $this->client()->domains()->list();

        if ($domains === []) {
            self::markTestSkipped('The account has no domains.');
        }

        $domain = $domains[0];
        self::assertGreaterThan(0, $domain->id, 'A domain should carry a numeric id');
        self::assertNotSame('', $domain->domain, 'A domain should carry its name');
        self::assertInstanceOf(
            DomainStatus::class,
            $domain->status,
            \sprintf('Unrecognised domain status "%s" — the enum needs a new case.', (string) ($domain->raw['status'] ?? '')),
        );
    }

    public function testGetDomainAgreesWithList(): void
    {
        $domains = $this->client()->domains()->list();
        if ($domains === []) {
            self::markTestSkipped('The account has no domains.');
        }

        $fetched = $this->client()->domains()->get($domains[0]->id);

        self::assertSame($domains[0]->domain, $fetched->domain);
        self::assertSame($domains[0]->id, $fetched->id);
    }

    public function testFindByNameMatchesExactly(): void
    {
        $domains = $this->client()->domains()->list();
        if ($domains === []) {
            self::markTestSkipped('The account has no domains.');
        }

        $found = $this->client()->domains()->findByName($domains[0]->domain);

        self::assertInstanceOf(Domain::class, $found);
        self::assertSame($domains[0]->id, $found->id);
    }

    /**
     * A domain id outside the account answers **403, not 404** — and does so
     * even for an id that cannot exist at all. The API never reveals whether a
     * domain exists, only whether it is yours, which stops the endpoint being
     * used to enumerate the register. Undocumented; verified live.
     */
    public function testADomainIdOutsideTheAccountIsForbiddenNotNotFound(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->client()->domains()->get(1);
    }

    public function testEvenAnImpossibleDomainIdIsForbiddenRatherThanNotFound(): void
    {
        // Same answer for an id far beyond anything allocated: the API declines
        // to distinguish "does not exist" from "not yours".
        $this->expectException(ForbiddenException::class);
        $this->client()->domains()->get(999_999_999);
    }

    public function testListDnsRecordsDecodesEveryTypeTheZoneUses(): void
    {
        $hosted = $this->domainsWithDns();
        if ($hosted === []) {
            self::markTestSkipped('No domain in the account uses Domeneshop DNS.');
        }

        // fromArray() throws on a type we do not model, so simply listing the
        // zone proves the record hierarchy covers what the API actually returns.
        $records = $this->client()->dns()->list($hosted[0]->id);

        self::assertIsArray($records);
    }

    public function testListForwardsDecodesForADomainWithDnsService(): void
    {
        $hosted = $this->domainsWithDns();
        if ($hosted === []) {
            self::markTestSkipped('No domain in the account uses Domeneshop DNS.');
        }

        self::assertIsArray($this->client()->forwards()->list($hosted[0]->id));
    }

    /**
     * Forwarding lives behind the DNS service, so a domain without it answers
     * **404** on the forwards collection rather than returning an empty list.
     * The 404 means "forwarding is not available here", not "no such domain".
     * Undocumented; verified live.
     */
    public function testListingForwardsOnADomainWithoutDnsServiceIsA404(): void
    {
        $withoutDns = array_values(array_filter(
            $this->client()->domains()->list(),
            static fn (Domain $d): bool => $d->services?->dns === false,
        ));

        if ($withoutDns === []) {
            self::markTestSkipped('Every domain in the account has DNS service.');
        }

        $this->expectException(NotFoundException::class);
        $this->client()->forwards()->list($withoutDns[0]->id);
    }

    /** @return list<Domain> */
    private function domainsWithDns(): array
    {
        return array_values(array_filter(
            $this->client()->domains()->list(),
            static fn (Domain $d): bool => $d->services?->dns === true,
        ));
    }

    public function testListInvoicesDecodesEveryEnum(): void
    {
        $invoices = $this->client()->invoices()->list();

        foreach ($invoices as $invoice) {
            self::assertNotNull(
                $invoice->status,
                \sprintf('Unrecognised invoice status "%s" — the enum needs a new case.', (string) ($invoice->raw['status'] ?? '')),
            );
            self::assertNotNull(
                $invoice->currency,
                \sprintf('Unrecognised currency "%s" — the enum needs a new case.', (string) ($invoice->raw['currency'] ?? '')),
            );
        }

        self::assertIsArray($invoices);
    }

    public function testTheInvoiceStatusFilterIsHonoured(): void
    {
        $paid = $this->client()->invoices()->list(InvoiceStatus::Paid);

        foreach ($paid as $invoice) {
            self::assertSame(InvoiceStatus::Paid, $invoice->status);
        }

        self::assertIsArray($paid);
    }
}
