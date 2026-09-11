<?php

use App\Models\ProductionOrder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Older active orders were dated as one continuous pipeline. Re-date only
     * unfinished work: sample steps finish within three days and batch steps
     * use the remaining time through the client's original delivery date.
     */
    public function up(): void
    {
        ProductionOrder::query()
            ->where('status', 'active')
            ->whereNotNull('due_date')
            ->orderBy('id')
            ->eachById(function (ProductionOrder $order): void {
                // Do not start a clock for an unpaid order. It will receive
                // this split automatically when Finance confirms payment.
                if (! $order->hasDownpayment()) {
                    return;
                }

                $order->scheduleStepDeadlines(null, preserveCompleted: true);
                $order->applySampleDueDate();
            });
    }

    public function down(): void
    {
        // The old single-window dates cannot be reconstructed safely.
    }
};
