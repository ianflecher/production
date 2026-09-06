<?php

/**
 * Throwaway: walks one order from the officer's desk to the door and prints
 * what opens at each step, so the sample -> batch handover can be watched.
 *
 * Run from the application folder:  php ../walk-pipeline.php
 */

use App\Models\ProductionOrder;
use App\Models\TechPack;
use App\Models\User;

require __DIR__.'/application/vendor/autoload.php';

$app = require_once __DIR__.'/application/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

function line(string $text = ''): void
{
    echo $text.PHP_EOL;
}

/** Every step that is open right now, and which stage it belongs to. */
function openSteps(ProductionOrder $order): array
{
    return $order->fresh()->tasks()
        ->whereIn('status', ['ready', 'in_progress', 'for_checking', 'revision_required'])
        ->orderBy('sequence')
        ->get()
        ->map(fn ($t) => $t->department.' (stage '.$t->stage.', '.$t->status.')')
        ->all();
}

function statusOf(ProductionOrder $order, string $department): string
{
    return (string) $order->fresh()->tasks()->where('department', $department)->value('status');
}

function close(ProductionOrder $order, string $department, ?int $stage = null): void
{
    $task = $order->fresh()->tasks()
        ->where('department', $department)
        ->when($stage !== null, fn ($q) => $q->where('stage', $stage))
        ->whereNotIn('status', ['complete', 'cancelled'])
        ->first();

    if (! $task) {
        line('   !! nothing open called "'.$department.'"'.($stage ? ' at stage '.$stage : ''));

        return;
    }

    $task->update(['status' => 'complete', 'approved_at' => now(), 'released_at' => now()]);
    $order->refresh()->handleTaskCompleted($task->fresh());
}

function closeStage(ProductionOrder $order, int $stage): void
{
    $open = $order->fresh()->tasks()
        ->where('stage', $stage)
        ->whereNotIn('status', ['complete', 'cancelled'])
        ->orderBy('sequence')
        ->pluck('department')
        ->all();

    foreach ($open as $department) {
        close($order, $department, $stage);
    }
}

// ---------------------------------------------------------------------------

$officer = User::where('job_role', User::ROLE_SALES)->firstOrFail();
$artist = User::where('job_role', User::JOB_ARTIST)->firstOrFail();

ProductionOrder::where('order_number', 'IC2026-WALK9')->each(function ($old) {
    $old->tasks()->delete();
    $old->techPacks()->delete();
    $old->jobOrder()?->delete();
    $old->items()->delete();
    $old->delete();
});

$order = ProductionOrder::create([
    'order_number' => 'IC2026-WALK9',
    'customer_name' => 'Pipeline Walk',
    'product_type' => 'round_neck',
    'quantity' => 20,
    'due_date' => now()->addWeeks(3),
    'created_by' => $officer->id,
    'status' => 'active',
]);

$order->jobOrder()->create([
    'status' => 'sent_to_artist',
    'created_by' => $officer->id,
    'print_type' => 'dtf',
    'printer' => 'dtf_printer',
    'fabric' => 'Cotton blend',
]);

$order->buildPipeline([], 'manual');

line('=========================================================');
line('  '.$order->order_number.' - from the officer to the door');
line('=========================================================');
line();
line('The steps this job was given:');

foreach ($order->fresh()->tasks()->orderBy('sequence')->get() as $t) {
    line(sprintf('   stage %-3s %s', $t->stage, $t->department));
}

line();
line('--- 1-2. Layout, mockup, and the SAMPLE tech pack --------');
close($order, 'Layout');
close($order, 'Final mockup');
line('   open now: '.implode(', ', openSteps($order)));

$order->openTechPack(TechPack::PHASE_SAMPLE)->fill(['design_name' => 'Walk'])->save();
close($order, 'Tech pack');
line('   sample tech pack signed off.');
line('   open now: '.implode(', ', openSteps($order)));

line();
line('--- 3. Print and materials ------------------------------');
closeStage($order, 3);
line('   open now: '.implode(', ', openSteps($order)));

line();
line('--- 5-8. The SAMPLE is cut, paired, sewn and checked ----');
foreach ([5, 6, 7, 8] as $stage) {
    closeStage($order, $stage);
    line('   stage '.$stage.' closed -> open now: '.implode(', ', openSteps($order)));
}

line();
line('--- 9. The client is shown the sample -------------------');
line('   BEFORE the client answers:');
line('      batch tech pack : '.statusOf($order, ProductionOrder::STEP_TECH_PACK_MASSPROD));
line('      Mass production : '.statusOf($order, 'Mass production'));

close($order, 'Produce sample for client');

line('   AFTER the client approves the sample:');
line('      batch tech pack : '.statusOf($order, ProductionOrder::STEP_TECH_PACK_MASSPROD).'   <-- opens here');
line('      Mass production : '.statusOf($order, 'Mass production').'   <-- still waiting on the sheet');

line();
line('--- 10. The batch sheet, then mass production -----------');
close($order, ProductionOrder::STEP_TECH_PACK_MASSPROD);
line('   batch sheet signed off.');
line('      Mass production : '.statusOf($order, 'Mass production').'   <-- released');

$batchPack = $order->fresh()->techPackFor(TechPack::PHASE_MASSPROD);
line('   batch sheet is a copy of the sample: design_name = '
    .var_export($batchPack?->design_name, true));

closeStage($order, 10);
line('   open now: '.implode(', ', openSteps($order)));

line();
line('--- 11-16. The batch walks the same line ----------------');
foreach ([11, 12, 13, 14, 15, 16] as $stage) {
    closeStage($order, $stage);
    $open = openSteps($order);
    line('   stage '.$stage.' closed -> open now: '.($open ? implode(', ', $open) : 'nothing left'));
}

line();
line('=========================================================');
$left = $order->fresh()->tasks()->whereNotIn('status', ['complete', 'cancelled'])->count();
line('  steps still open at the end: '.$left);
line('  order status: '.$order->fresh()->status);
line('=========================================================');
