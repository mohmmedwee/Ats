<?php

namespace App\Modules\Procurement\Application;

use App\Modules\Orders\Contracts\OrderBook;
use App\Modules\Procurement\Domain\SupplierOrderStatus as S;
use App\Modules\Procurement\Infrastructure\Models\ProcurementTask;
use App\Modules\Procurement\Infrastructure\Models\SupplierOrder;
use App\Modules\Suppliers\Contracts\ConnectorResolver;
use App\Modules\Suppliers\Contracts\SourcingDirectory;
use App\Modules\Suppliers\Contracts\SupplierConnector;
use App\Modules\Suppliers\Domain\Connector\ConnectorException;
use App\Modules\Suppliers\Domain\Connector\PurchaseLine;
use App\Modules\Suppliers\Domain\Connector\PurchaseRequest;
use App\Modules\Suppliers\Domain\Connector\PurchaseResult;
use App\Modules\Suppliers\Domain\Connector\TransientConnectorException;
use App\Modules\Suppliers\Domain\Connector\UnknownOutcomeException;
use App\Shared\Money\Decimal;
use App\Shared\Outbox\Outbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Automated purchasing (docs/PROCUREMENT_WORKFLOW.md §3). Guarantees at most one purchase per
 * supplier order through: task claim with SKIP LOCKED, supplier-order status checked under lock,
 * a unique idempotency key sent to the supplier, and reconciliation (never a blind retry) after
 * an unknown outcome.
 */
final class ProcurementEngine
{
    public function __construct(
        private readonly ConnectorResolver $connectors,
        private readonly SourcingDirectory $sourcing,
        private readonly OrderBook $orders,
        private readonly FulfillmentEvents $events,
        private readonly AttemptLog $log,
    ) {}

    /** @return string outcome label (for logs and tests) */
    public function process(string $taskId): string
    {
        $claimed = $this->claim($taskId);
        if ($claimed === null) {
            return 'skipped';
        }
        [$task, $so] = $claimed;

        try {
            $connector = $this->connectors->forSupplier($so->supplier_id);
            $eligibility = $this->eligibility($so, $connector);
            if ($eligibility === 'manual') {
                return $this->toManual($task, $so, 'Supplier requires manual purchasing.');
            }
            if ($eligibility !== null) {
                return $this->toException($task, $so, $eligibility, []);
            }

            // Revalidate stock and cost immediately before purchasing.
            $lines = $this->lines($so);
            foreach ($lines as $line) {
                if (! $connector->checkAvailability($line->externalProductId, $line->externalVariantId)->isAvailable()) {
                    return $this->toException($task, $so, 'OUT_OF_STOCK', ['external_variant_id' => $line->externalVariantId]);
                }
            }
            $quote = $connector->getQuote($lines);
            $current = Decimal::add($quote->itemsTotal, $quote->shippingTotal);
            $increase = Decimal::cmp($so->expected_cost, '0') > 0
                ? Decimal::mul(Decimal::div(Decimal::sub($current, $so->expected_cost, 6), $so->expected_cost, 6), '100', 2)
                : '0';
            if (Decimal::cmp($increase, (string) config('gulfcart.procurement.max_cost_increase_percent', '5')) > 0) {
                return $this->toException($task, $so, 'PRICE_INCREASE_ABOVE_TOLERANCE', ['expected' => $so->expected_cost, 'current' => $current, 'increase_percent' => $increase]);
            }
        } catch (TransientConnectorException $e) {
            return $this->retryLater($task, $so, 'revalidate', $e);
        } catch (ConnectorException $e) {
            return $this->toException($task, $so, 'SUPPLIER_ERROR', ['message' => $e->getMessage()]);
        }

        // Point of no return: the supplier may receive the order from here on.
        DB::transaction(function () use ($so) {
            SupplierOrder::query()->whereKey($so->id)->lockForUpdate()->firstOrFail()->moveTo(S::SUBMITTING);
        });
        $so->refresh();

        $request = new PurchaseRequest($so->reference, $lines, $so->shipping_address);
        $started = microtime(true);
        try {
            $result = $connector->createOrder($request, $so->idempotency_key);
        } catch (TransientConnectorException $e) {
            $this->log->record($task, 'submit', 'not_processed', 'safe_retry', $so->idempotency_key, $request, ['error' => $e->getMessage()], $started);

            return $this->retryLater($task, $so, 'submit', $e);
        } catch (UnknownOutcomeException $e) {
            $this->log->record($task, 'submit', 'unknown', 'unsafe', $so->idempotency_key, $request, ['error' => $e->getMessage()], $started);

            return $this->toUnknown($task, $so, $e->getMessage());
        } catch (Throwable $e) {
            // Anything unexpected after sending is treated as an unknown outcome.
            $this->log->record($task, 'submit', 'unknown', 'unsafe', $so->idempotency_key, $request, ['error' => $e::class.': '.$e->getMessage()], $started);

            return $this->toUnknown($task, $so, $e->getMessage());
        }

        $this->log->record($task, 'submit', $result->outcome, null, $so->idempotency_key, $request, $result->raw, $started);

        return match ($result->outcome) {
            PurchaseResult::ACCEPTED => $this->toPurchased($task, $so, $result->externalOrderId, $result->totalCost, $result->raw),
            PurchaseResult::MANUAL_REQUIRED => $this->toManual($task, $so, (string) $result->reason),
            default => $this->toException($task, $so, 'SUPPLIER_REJECTED', ['reason' => $result->reason, 'response' => $result->raw]),
        };
    }

    /** After an unknown outcome: ask the supplier whether the order exists. */
    public function reconcile(string $supplierOrderId): string
    {
        $so = SupplierOrder::query()->with('task')->find($supplierOrderId);
        if ($so === null || ! in_array($so->status, [S::UNKNOWN, S::SUBMITTING], true) || $so->task === null) {
            return 'skipped';
        }
        $task = $so->task;
        $connector = $this->connectors->forSupplier($so->supplier_id);
        $started = microtime(true);

        try {
            $found = $connector->findOrderByReference($so->idempotency_key);
        } catch (ConnectorException $e) {
            $this->log->record($task, 'reconcile', 'error', 'safe_retry', $so->idempotency_key, null, ['error' => $e->getMessage()], $started);
            $task->update(['status' => 'reconciling', 'next_attempt_at' => now()->addMinutes(5)]);

            return 'reconcile_failed';
        }
        $this->log->record($task, 'reconcile', $found ? 'found' : 'not_found', null, $so->idempotency_key, null, $found ? ['external_order_id' => $found->externalOrderId, 'status' => $found->status] : [], $started);

        if ($found !== null) {
            return $this->toPurchased($task, $so, $found->externalOrderId, $found->totalCost, ['reconciled' => true, 'status' => $found->status]);
        }

        if ($connector->capabilities()->idempotentOrders) {
            // The supplier deduplicates by key, so resubmitting with the SAME key is safe.
            DB::transaction(function () use ($so, $task) {
                $locked = SupplierOrder::query()->whereKey($so->id)->lockForUpdate()->firstOrFail();
                $from = $locked->status;
                $locked->moveTo(S::PENDING);
                $this->events->statusChanged($locked, $from);
                $task->update(['status' => 'waiting_retry', 'next_attempt_at' => now(), 'locked_at' => null, 'locked_by' => null]);
            });

            return 'retry_same_key';
        }

        return $this->toException($task, $so, 'UNKNOWN_OUTCOME_UNRESOLVED', ['message' => 'Supplier has no order for this reference and does not guarantee idempotency. Confirm with the supplier before retrying.']);
    }

    /** @return array{0: ProcurementTask, 1: SupplierOrder}|null */
    private function claim(string $taskId): ?array
    {
        return DB::transaction(function () use ($taskId) {
            $task = ProcurementTask::query()->whereKey($taskId)->whereIn('status', ['queued', 'waiting_retry'])
                ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
                ->lock('FOR UPDATE SKIP LOCKED')->first();
            if ($task === null) {
                return null;
            }
            $so = SupplierOrder::query()->whereKey($task->supplier_order_id)->lockForUpdate()->firstOrFail();
            if ($so->status !== S::PENDING) {
                // Already handled (e.g. purchased, cancelled): never purchase twice.
                $task->update(['status' => in_array($so->status, S::COMMITTED, true) ? 'succeeded' : 'cancelled']);

                return null;
            }
            if ($this->orders->lockForFulfillment($so->order_id) === null) {
                $from = $so->status;
                $so->moveTo(S::CANCELLED);
                $this->events->statusChanged($so, $from);
                $task->update(['status' => 'cancelled']);

                return null;
            }
            $task->update(['status' => 'in_progress', 'attempts' => $task->attempts + 1, 'locked_at' => now(), 'locked_by' => gethostname().':'.getmypid()]);

            return [$task, $so];
        });
    }

    private function eligibility(SupplierOrder $so, SupplierConnector $connector): ?string
    {
        $countryCode = (string) ($so->shipping_address['country'] ?? '');
        $order = $this->orders->get($so->order_id);
        foreach ($so->items()->get() as $item) {
            $variantId = $order?->item($item->order_item_id)?->variantId;
            $eligible = array_filter($this->sourcing->sourcesFor((string) $variantId, $countryCode), fn ($s) => $s->supplierProductId === $item->supplier_product_id);
            if ($eligible === []) {
                // Supplier deactivated, authorization lapsed or the item is out of stock in our data.
                return 'SUPPLIER_NOT_ELIGIBLE';
            }
            $source = reset($eligible);
            if (! $source->allowsApiPurchasing || ! $connector->capabilities()->automatedPurchasing) {
                return 'manual';
            }
        }

        return null;
    }

    /** @return list<PurchaseLine> */
    private function lines(SupplierOrder $so): array
    {
        return $so->items()->get()->map(fn ($i) => new PurchaseLine($i->external_product_id, $i->external_variant_id, $i->purchase_quantity, (string) $i->purchase_cost, $i->currency))->all();
    }

    /** @param array<string, mixed> $raw */
    private function toPurchased(ProcurementTask $task, SupplierOrder $so, ?string $externalId, ?string $cost, array $raw): string
    {
        DB::transaction(function () use ($task, $so, $externalId, $cost, $raw) {
            $locked = SupplierOrder::query()->whereKey($so->id)->lockForUpdate()->firstOrFail();
            $this->events->move($locked, S::PURCHASED, [
                'external_order_id' => $externalId, 'actual_cost' => $cost, 'purchased_at' => now(),
                'supplier_response' => $raw, 'exception_reason' => null, 'exception_details' => null,
            ]);
            $task->update(['status' => 'succeeded', 'locked_at' => null, 'locked_by' => null, 'last_error' => null]);
        });

        return 'purchased';
    }

    private function toManual(ProcurementTask $task, SupplierOrder $so, string $reason): string
    {
        DB::transaction(function () use ($task, $so, $reason) {
            $locked = SupplierOrder::query()->whereKey($so->id)->lockForUpdate()->firstOrFail();
            $this->events->move($locked, S::MANUAL, ['procurement_mode' => 'manual']);
            $task->update(['status' => 'manual', 'locked_at' => null, 'locked_by' => null, 'last_error' => ['reason' => 'MANUAL_PROCUREMENT', 'message' => $reason]]);
            Outbox::record('supplier_order', $so->id, 'procurement.manual_required', ['supplier_order_id' => $so->id, 'order_id' => $so->order_id]);
        });

        return 'manual';
    }

    /** @param array<string, mixed> $details */
    private function toException(ProcurementTask $task, SupplierOrder $so, string $reason, array $details): string
    {
        DB::transaction(function () use ($task, $so, $reason, $details) {
            $locked = SupplierOrder::query()->whereKey($so->id)->lockForUpdate()->firstOrFail();
            $this->events->move($locked, S::EXCEPTION, ['exception_reason' => $reason, 'exception_details' => $details]);
            $task->update(['status' => 'exception', 'locked_at' => null, 'locked_by' => null, 'last_error' => ['reason' => $reason] + $details]);
            Outbox::record('supplier_order', $so->id, 'procurement.exception', ['supplier_order_id' => $so->id, 'order_id' => $so->order_id, 'reason' => $reason]);
        });
        Log::warning('procurement.exception', ['supplier_order' => $so->reference, 'reason' => $reason]);

        return 'exception';
    }

    private function toUnknown(ProcurementTask $task, SupplierOrder $so, string $message): string
    {
        DB::transaction(function () use ($task, $so, $message) {
            $locked = SupplierOrder::query()->whereKey($so->id)->lockForUpdate()->firstOrFail();
            $this->events->move($locked, S::UNKNOWN);
            $task->update(['status' => 'reconciling', 'next_attempt_at' => now()->addMinutes(5), 'locked_at' => null, 'locked_by' => null, 'last_error' => ['reason' => 'UNKNOWN_OUTCOME', 'message' => $message]]);
        });
        Log::warning('procurement.unknown_outcome', ['supplier_order' => $so->reference]);

        return 'unknown';
    }

    private function retryLater(ProcurementTask $task, SupplierOrder $so, string $stage, Throwable $e): string
    {
        $backoff = (array) config('gulfcart.procurement.retry_backoff_minutes', [1, 5, 15, 60, 240]);
        if ($task->attempts >= count($backoff)) {
            if ($so->refresh()->status === S::SUBMITTING) {
                DB::transaction(fn () => SupplierOrder::query()->whereKey($so->id)->lockForUpdate()->firstOrFail()->moveTo(S::PENDING));
            }

            return $this->toException($task, $so->refresh(), 'RETRIES_EXHAUSTED', ['stage' => $stage, 'message' => $e->getMessage()]);
        }

        DB::transaction(function () use ($task, $so, $backoff, $stage, $e) {
            $locked = SupplierOrder::query()->whereKey($so->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === S::SUBMITTING) {
                $locked->moveTo(S::PENDING); // the supplier confirmed it did not process the request
            }
            $task->update([
                'status' => 'waiting_retry',
                'next_attempt_at' => now()->addMinutes((int) $backoff[$task->attempts - 1]),
                'locked_at' => null, 'locked_by' => null,
                'last_error' => ['reason' => 'TRANSIENT', 'stage' => $stage, 'message' => $e->getMessage()],
            ]);
        });

        return 'retry_scheduled';
    }
}
