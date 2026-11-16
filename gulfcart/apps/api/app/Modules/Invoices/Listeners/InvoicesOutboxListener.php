<?php

namespace App\Modules\Invoices\Listeners;

use App\Modules\Invoices\Application\InvoiceService;
use App\Shared\Outbox\OutboxMessagePublished;

final class InvoicesOutboxListener
{
    public function __construct(private readonly InvoiceService $invoices) {}

    public function handle(OutboxMessagePublished $e): void
    {
        match ($e->eventType) {
            'order.paid', 'order.cod_collected' => $this->invoices->issueInvoice((string) $e->payload['order_id']),
            'refund.succeeded' => $this->invoices->issueCreditNote((string) $e->payload['order_id'], (string) $e->payload['refund_id'], (string) $e->payload['amount'], (array) ($e->payload['item_quantities'] ?? [])),
            default => null,
        };
    }
}
