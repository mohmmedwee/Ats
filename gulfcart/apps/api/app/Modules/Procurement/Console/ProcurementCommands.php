<?php

namespace App\Modules\Procurement\Console;

use App\Modules\Procurement\Application\ProcurementEngine;
use App\Modules\Procurement\Application\Tracker;
use App\Modules\Procurement\Domain\SupplierOrderStatus as S;
use App\Modules\Procurement\Infrastructure\Models\ProcurementTask;
use App\Modules\Procurement\Infrastructure\Models\SupplierOrder;
use Illuminate\Console\Command;
use Throwable;

/**
 * procurement:run        due tasks (queued / waiting_retry), and stale in-progress claims
 * procurement:reconcile  supplier orders in submitting/unknown → ask the supplier
 * procurement:track      status and tracking for purchased supplier orders
 */
abstract class ProcurementCommands extends Command
{
    protected const ACTION = '';

    public function handle(ProcurementEngine $engine, Tracker $tracker): int
    {
        return match (static::ACTION) {
            'run' => $this->runDue($engine),
            'reconcile' => $this->reconcile($engine),
            'track' => $this->trackAll($tracker),
        };
    }

    private function runDue(ProcurementEngine $engine): int
    {
        $staleBefore = now()->subMinutes((int) config('gulfcart.procurement.stale_lock_minutes', 10));
        // A worker died mid-run: if it never reached the supplier the order is still pending (safe);
        // if it did, the supplier order is in submitting and goes to reconciliation instead.
        ProcurementTask::query()->where('status', 'in_progress')->where('locked_at', '<', $staleBefore)->get()
            ->each(function (ProcurementTask $t) {
                $so = SupplierOrder::query()->find($t->supplier_order_id);
                $t->update($so?->status === S::SUBMITTING
                    ? ['status' => 'reconciling', 'next_attempt_at' => now(), 'locked_at' => null, 'locked_by' => null]
                    : ['status' => 'waiting_retry', 'next_attempt_at' => now(), 'locked_at' => null, 'locked_by' => null]);
            });

        $ids = ProcurementTask::query()->whereIn('status', ['queued', 'waiting_retry'])
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('next_attempt_at')->limit(200)->pluck('id');
        foreach ($ids as $id) {
            $this->line($id.': '.$engine->process($id));
        }

        return self::SUCCESS;
    }

    private function reconcile(ProcurementEngine $engine): int
    {
        SupplierOrder::query()->whereIn('status', [S::UNKNOWN, S::SUBMITTING])
            ->where('updated_at', '<', now()->subMinutes(5))
            ->whereHas('task', fn ($q) => $q->where('status', 'reconciling')->where(fn ($w) => $w->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now())))
            ->pluck('id')->each(fn ($id) => $this->line($id.': '.$engine->reconcile($id)));

        return self::SUCCESS;
    }

    private function trackAll(Tracker $tracker): int
    {
        SupplierOrder::query()->whereIn('status', [S::PURCHASED, S::SUPPLIER_PROCESSING, S::SHIPPED, S::CANCEL_REQUESTED])
            ->where(fn ($q) => $q->whereNull('last_tracked_at')->orWhere('last_tracked_at', '<', now()->subMinutes(20)))
            ->limit(500)->get()
            ->each(function (SupplierOrder $so) use ($tracker) {
                try {
                    $this->line($so->reference.': '.($tracker->track($so) ?? 'unchanged'));
                } catch (Throwable $e) {
                    report($e);
                }
            });

        return self::SUCCESS;
    }
}
