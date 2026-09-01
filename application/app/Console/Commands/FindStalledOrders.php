<?php

namespace App\Console\Commands;

use App\Models\ProductionOrder;
use App\Models\Task;
use Illuminate\Console\Command;

/**
 * Find jobs that are waiting for something that will never come.
 *
 * IC2026-00001 sat for days with its layout approved and nothing owed on it,
 * while every screen said it was waiting for a downpayment that could not
 * arrive. Nothing noticed, because the shop only ever looks at a job when
 * somebody remembers it.
 *
 * A stage opens as a CONSEQUENCE of something happening — money confirmed, a
 * task completed. If the thing already happened before the rule existed, or
 * happened in a way that skipped the trigger, the job stops and no screen says
 * why. This finds those, and can push them on.
 *
 *   php artisan orders:stalled
 *   php artisan orders:stalled --fix
 */
class FindStalledOrders extends Command
{
    protected $signature = 'orders:stalled {--fix : Open the stage that should already be open}';

    protected $description = 'Jobs whose next stage should be open but is not';

    public function handle(): int
    {
        $stalled = ProductionOrder::with('tasks')
            ->whereIn('status', ['active', 'on_hold'])
            ->get()
            ->filter(fn (ProductionOrder $order) => $this->stuckStage($order) !== null);

        if ($stalled->isEmpty()) {
            $this->info('Nothing is stuck.');

            return self::SUCCESS;
        }

        foreach ($stalled as $order) {
            $stage = $this->stuckStage($order);

            $this->warn(sprintf(
                '%s — %s: stage %d should be open (%s)',
                $order->order_number,
                $order->clientName(),
                $stage,
                $order->owesNothing() ? 'nothing owed' : 'payment confirmed'
            ));

            if ($this->option('fix')) {
                $order->unlockStage($stage);
                $this->line('   opened.');
            }
        }

        if (! $this->option('fix')) {
            $this->line('');
            $this->line('Run again with --fix to open them.');
        }

        return self::SUCCESS;
    }

    /**
     * The stage that ought to be open on this job and is not, or null.
     *
     * Only the one gate that has actually bitten: the layout is finished and
     * the money is settled, so the mockup should be with the artist. Written
     * as its own method so the next gate that needs watching is added here
     * rather than discovered by a client asking where their shirts are.
     */
    private function stuckStage(ProductionOrder $order): ?int
    {
        $layout = $order->tasks->where('stage', ProductionOrder::STAGE_LAYOUT);

        if ($layout->isEmpty() || ! $layout->every(fn (Task $t) => $t->status === 'complete')) {
            return null;
        }

        if (! $order->hasDownpayment()) {
            return null;
        }

        $mockup = $order->tasks->filter(
            fn (Task $t) => str_starts_with($t->department, 'Final mockup')
        );

        if ($mockup->isNotEmpty() && $mockup->every(fn (Task $t) => $t->status === 'todo')) {
            return ProductionOrder::STAGE_MOCKUP;
        }

        return null;
    }
}
