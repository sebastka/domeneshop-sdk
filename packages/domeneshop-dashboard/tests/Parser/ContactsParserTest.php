<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Dashboard\Tests\Parser;

use Sebastka\Domeneshop\Dashboard\Exception\UnexpectedPageException;
use Sebastka\Domeneshop\Dashboard\Model\BillingContact;
use Sebastka\Domeneshop\Dashboard\Model\Contact;
use Sebastka\Domeneshop\Dashboard\Parser\ContactsParser;
use Sebastka\Domeneshop\Dashboard\Tests\DashboardTestCase;

final class ContactsParserTest extends DashboardTestCase
{
    private const PATH = '/admin?id=1&edit=contacts';

    /** `.no`: an owner only, with a handle and organisation number, no fax. */
    public function testANorwegianDomain(): void
    {
        $contacts = ContactsParser::parse(self::fixture('contacts-no'), self::PATH);

        self::assertEquals(new Contact(
            handle: 'HDL123',
            name: 'Ola Nordmann',
            organization: 'Example Holding AS',
            orgNumber: '999999999',
            firstName: 'Ola',
            lastName: 'Nordmann',
            address: 'Eksempelveien 1',
            zip: '0001',
            city: 'Oslo',
            country: 'no',
            phone: null,
            fax: null,
            email: 'ola@example.com',
        ), $contacts->owner);

        self::assertNull($contacts->admin);
        self::assertNull($contacts->tech);
        self::assertTrue($contacts->hideEmail);
        self::assertNull($contacts->hidePersonalData, '.no offers no such option');
    }

    /**
     * `.info` (and `.com`, `.org`, `.app`, `.fr`): owner, admin and tech, fax
     * fields, and an owner whose name is displayed but not in the name fields.
     */
    public function testAGenericTld(): void
    {
        $contacts = ContactsParser::parse(self::fixture('contacts-generic'), self::PATH);

        self::assertEquals(new Contact(
            name: 'Ola Nordmann',
            organization: 'Example Holding AS',
            address: 'Eksempelveien 1',
            zip: '0001',
            city: 'Oslo',
            country: 'no',
            phone: '+47.22222222',
            email: 'ola@example.com',
            extra: ['name' => 'ro'],
        ), $contacts->owner);

        self::assertNotNull($contacts->admin);
        self::assertSame(['Kari', 'Nordmann', 'kari@example.com'], [$contacts->admin->firstName, $contacts->admin->lastName, $contacts->admin->email]);
        self::assertNull($contacts->admin->organization, 'an empty field is null');
        self::assertNull($contacts->admin->handle);

        self::assertNotNull($contacts->tech);
        self::assertSame(['Per', 'per@example.com', 'no'], [$contacts->tech->firstName, $contacts->tech->email, $contacts->tech->country]);

        self::assertTrue($contacts->hideEmail);
        self::assertTrue($contacts->hidePersonalData);
    }

    /**
     * `.no` with a private-person holder: a PID instead of an organisation
     * number, a technical contact, and the owner's name fields sent twice —
     * filled, then empty. The filled ones must win.
     */
    public function testANorwegianDomainWithAPrivateHolderAndTechContact(): void
    {
        $contacts = ContactsParser::parse(self::fixture('contacts-no-tech'), self::PATH);

        self::assertSame('Ola', $contacts->owner->firstName);
        self::assertSame('Nordmann', $contacts->owner->lastName);
        self::assertSame('Ola Nordmann', $contacts->owner->name);
        self::assertSame('HDL123', $contacts->owner->handle);
        self::assertNull($contacts->owner->orgNumber);
        self::assertSame('N.PRI.99999999', $contacts->owner->extra['vatno'] ?? null);

        self::assertNull($contacts->admin);
        self::assertNotNull($contacts->tech);
        self::assertSame('per@example.com', $contacts->tech->email);
        self::assertTrue($contacts->hideEmail);
    }

    /** Whichever order the duplicates come in, an empty copy never hides a filled one. */
    public function testAnEmptyDuplicateNeverHidesAFilledOne(): void
    {
        // Empty the first copy, then fill the second: the reverse of the real page.
        $html = self::replaceOnce('name="o_firstname" value="Ola"', 'name="o_firstname" value=""', self::fixture('contacts-no-tech'));
        $html = (string) preg_replace('/(name="o_firstname" value="".*?name="o_firstname" value=)""/s', '$1"Kari"', $html, 1, $count);
        self::assertSame(1, $count);

        self::assertSame('Kari', ContactsParser::parse($html, self::PATH)->owner->firstName);
    }

    /** The "Kopier data fra" pickers are UI, not contact data. */
    public function testTheCopyFromPickersAreNotFields(): void
    {
        $contacts = ContactsParser::parse(self::fixture('contacts-generic'), self::PATH);

        self::assertNotNull($contacts->admin);
        self::assertArrayNotHasKey('type', $contacts->admin->extra);
    }

    public function testTheBillingContact(): void
    {
        $expected = new BillingContact('Kari', 'Nordmann', 'Eksempelveien 2', '0002', 'Oslo', 'Norge', '+47 22 22 22 22', 'kari@example.com');

        self::assertEquals($expected, ContactsParser::parse(self::fixture('contacts-no'), self::PATH)->billing);
        self::assertEquals($expected, ContactsParser::parse(self::fixture('contacts-generic'), self::PATH)->billing);
    }

    /** A TLD with a field nobody modelled keeps it, rather than losing it. */
    public function testAnUnknownFieldIsKeptInExtra(): void
    {
        $html = self::replaceOnce(
            '<input type="text" name="o_city"',
            '<input type="text" name="o_birthdate" value="1990-01-01"><input type="text" name="o_city"',
            self::fixture('contacts-no'),
        );

        self::assertSame(['birthdate' => '1990-01-01'], ContactsParser::parse($html, self::PATH)->owner->extra);
    }

    public function testAMissingOwnerIsUnexpected(): void
    {
        $html = (string) preg_replace('/name="o_/', 'name="x_', self::fixture('contacts-no'));

        $this->expectException(UnexpectedPageException::class);
        $this->expectExceptionMessage('owner');
        ContactsParser::parse($html, self::PATH);
    }

    public function testAMissingBillingContactIsUnexpected(): void
    {
        $html = self::replaceOnce('<b>Betalingskontakt:</b>', '<b>Faktura:</b>', self::fixture('contacts-no'));

        $this->expectException(UnexpectedPageException::class);
        $this->expectExceptionMessage('billing');
        ContactsParser::parse($html, self::PATH);
    }

    public function testAnotherPageIsUnexpected(): void
    {
        $this->expectException(UnexpectedPageException::class);
        ContactsParser::parse(self::fixture('nameservers'), self::PATH);
    }

    public function testToArrayUsesSnakeCaseKeys(): void
    {
        $array = ContactsParser::parse(self::fixture('contacts-no'), self::PATH)->toArray();

        self::assertSame(['owner', 'admin', 'tech', 'billing', 'hide_email', 'hide_personal_data'], array_keys($array));
        self::assertSame('999999999', $array['owner']['org_number']);
        self::assertSame('Norge', $array['billing']['country']);
    }
}
