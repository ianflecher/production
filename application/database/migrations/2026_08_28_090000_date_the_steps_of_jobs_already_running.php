<?php

use App\Models\ProductionOrder;
use Illuminate\Database\Migrations\Migration;

/**
 * Give the jobs already running their step dates.
 *
 * Deadlines are worked out when Finance confirms the downpayment, so every
 * order that was already on the floor when that arrived has none — the whole
 * column reads as dashes on exactly the jobs somebody is chasing.
 *
 * Worked out from the same two facts as any other order: when the money was
 * confirmed, and when the client is expecting it. Orders that are finished or
 * cancelled are left alone; nobody is chasing those.
 */
return new class extends Migration
{
    public function up(): void
    {
        ProductionOrder::query()
            ->whereNotNull('due_date')
            ->whereNotIn('status', ['cancelled', 'complete'])
            ->with('payments')
            ->chunkById(100, function ($orders) {
                foreach ($orders as $order) {
                    // No confirmed money means the job has not started, and a
                    // deadline measured from nothing would be a guess.
                    if (! $order->hasDownpayment()) {
                        continue;
                    }

                    $order->scheduleStepDeadlines();
                }
            });
    }

    public function down(): void
    {
        // Deliberately nothing: the dates are worked out, not collected, and
        // the next confirmation writes them again.
    }
};
