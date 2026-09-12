<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Tests\Resource;

use Sebastka\Domeneshop\Model\Currency;
use Sebastka\Domeneshop\Model\InvoiceStatus;
use Sebastka\Domeneshop\Model\InvoiceType;
use Sebastka\Domeneshop\Tests\DomeneshopTestCase;

final class InvoicesTest extends DomeneshopTestCase
{
    public function testListMapsInvoices(): void
    {
        $client = $this->client(self::json([[
            'id' => 1,
            'type' => 'invoice',
            'amount' => 120,
            'currency' => 'NOK',
            'due_date' => '2026-02-01',
            'issued_date' => '2026-01-01',
            'paid_date' => '2026-01-15',
            'status' => 'paid',
            'url' => 'https://www.domeneshop.no/invoice?nr=1&code=',
        ]]));

        $invoices = $client->invoices()->list();

        self::assertSame('/v0/invoices', $this->http->lastPath());
        self::assertSame(1, $invoices[0]->id);
        self::assertSame(InvoiceType::Invoice, $invoices[0]->type);
        self::assertSame(120, $invoices[0]->amount);
        self::assertSame(Currency::NOK, $invoices[0]->currency);
        self::assertSame(InvoiceStatus::Paid, $invoices[0]->status);
        self::assertSame('2026-01-15', $invoices[0]->paidDate);
    }

    public function testListForwardsTheStatusFilter(): void
    {
        $client = $this->client(self::json([]));
        $client->invoices()->list(InvoiceStatus::Unpaid);

        self::assertSame(['status' => 'unpaid'], $this->http->lastQuery());
    }

    public function testUnpaidInvoiceHasNoPaidDate(): void
    {
        $client = $this->client(self::json([['id' => 2, 'status' => 'unpaid', 'amount' => 99]]));

        self::assertNull($client->invoices()->list()[0]->paidDate);
    }

    public function testCreditNoteTypeMirrorsTheApiSpelling(): void
    {
        $client = $this->client(self::json([['id' => 3, 'type' => 'credit_node', 'status' => 'settled']]));

        self::assertSame(InvoiceType::CreditNote, $client->invoices()->list()[0]->type);
    }

    public function testGetFetchesByNumber(): void
    {
        $client = $this->client(self::json(['id' => 77, 'amount' => 500]));

        self::assertSame(77, $client->invoices()->get(77)->id);
        self::assertSame('/v0/invoices/77', $this->http->lastPath());
    }
}
