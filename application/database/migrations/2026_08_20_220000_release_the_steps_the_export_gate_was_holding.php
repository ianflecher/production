<?php

use App\Models\ProductionOrder;
use Illuminate\Database\Migrations\Migration;

/**
 * Free the steps the Export gate left standing.
 *
 * Dropping the step and cancelling it on running orders was not enough. A step
 * whose prerequisite is not met when its stage opens is HELD as todo, and it is
 * released later by the prerequisite completing — so removing the prerequisite
 * outright freed nothing: the printer sat at TO DO on every order that had
 * already reached stage 3, with nothing left in the world that could open it.
 *
 * unlockStage re-runs the release pass over todo steps whose prerequisites are
 * now met, which is exactly the missing event.
 */
return new class extends Migration
{
    public function up(): void
    {
        ProductionOrder::where('status', 'active')
            ->whereHas('tasks', fn ($q) => $q->where('stage', '>', 2)->where('status', 'todo'))
            ->with('tasks')
            ->chunkById(50, function ($orders) {
                foreach ($orders as $order) {
                    // Only stages that have actually started: a stage with
                    // nothing released yet is still waiting its turn, and
                    // opening it here would run the line ahead of itself.
                    $started = $order->tasks
                        ->whereNotIn('status', ['todo', 'cancelled'])
                        ->pluck('stage')->unique();

                    foreach ($started as $stage) {
                        $order->unlockStage($stage);
                    }
                }
            });
    }

    public function down(): void
    {
        // Nothing to undo: this only opened steps that were already due.
    }
};
