<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Resource;

use Sebastka\Domeneshop\Exception\NotFoundException;
use Sebastka\Domeneshop\Http\Transport;
use OpenApi\Attributes as OA;
use Sebastka\Domeneshop\Model\Invoice;
use Sebastka\Domeneshop\Model\InvoiceStatus;

/** Invoices and credit notes (`/invoices`). Only the past three years. */
final class Invoices
{
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * List invoices, optionally narrowed to one status.
     *
     * @return list<Invoice>
     */
    #[OA\Get(
        path: '/invoices',
        operationId: 'listInvoices',
        summary: 'List invoices',
        description: 'List invoices for the account. Only invoices from the past three years are returned.',
        tags: ['invoices'],
        security: [['basicAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Only return invoices with this status.', schema: new OA\Schema(type: 'string', enum: ['unpaid', 'paid', 'settled'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Invoice'))),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
        ],
    )]
    public function list(?InvoiceStatus $status = null): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = (array) $this->transport->get('/invoices', ['status' => $status?->value]);

        return array_values(array_map(Invoice::fromArray(...), $rows));
    }

    /**
     * Fetch a single invoice by its number.
     *
     * @throws NotFoundException when no invoice has that number.
     */
    #[OA\Get(
        path: '/invoices/{invoiceId}',
        operationId: 'getInvoice',
        summary: 'Get invoice',
        description: 'Fetch a single invoice by its number.',
        tags: ['invoices'],
        security: [['basicAuth' => []]],
        parameters: [new OA\Parameter(ref: '#/components/parameters/InvoiceId')],
        responses: [
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 200, description: 'Successful operation', content: new OA\JsonContent(ref: '#/components/schemas/Invoice')),
            new OA\Response(response: 404, description: 'Invoice not found'),
        ],
    )]
    public function get(int $invoiceId): Invoice
    {
        /** @var array<string, mixed> $data */
        $data = (array) $this->transport->get(\sprintf('/invoices/%d', $invoiceId));

        return Invoice::fromArray($data);
    }
}
