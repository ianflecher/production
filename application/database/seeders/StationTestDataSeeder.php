<?php

namespace Database\Seeders;

use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Orders parked at a station, for trying the station sheet by hand.
 *
 * The account officer's part is filled in — collar, cuff, label, hem, sizes,
 * packaging. The seam record and the QC note are deliberately left EMPTY,
 * because those are the boxes the sewer and the checker are meant to fill at
 * the station, and the point of these orders is to try that.
 *
 * Re-runnable: it clears its own previous orders first, so you can reset the
 * test as many times as you like without touching anything else.
 *
 *     php artisan db:seed --class=StationTestDataSeeder
 */
class StationTestDataSeeder extends Seeder
{
    /** Every order this seeder makes is numbered like this, so it can find them again. */
    private const PREFIX = 'IC2026-TEST';

    /** Where to park them: department => how many. */
    private const PARK_AT = [
        'Sewing' => 2,
        'Quality control' => 1,
    ];

    public function run(): void
    {
        $sales = User::where('is_active', true)->get()
            ->first(fn ($u) => $u->isSales() || $u->isSuperAdmin());

        if (! $sales) {
            $this->command?->error('No account officer found — run the UserSeeder first.');

            return;
        }

        $this->clearPrevious();

        $n = 0;
        $made = [];

        foreach (self::PARK_AT as $department => $count) {
            for ($i = 1; $i <= $count; $i++) {
                $order = $this->makeOrder($sales, $department, ++$n, $i);
                $task = $order->fresh()->tasks()->where('department', $department)->first();

                $made[] = sprintf(
                    '  #%-4s %-18s  %-16s waiting at %s',
                    $order->id,
                    $order->order_number,
                    $order->customer_name,
                    $department.' ['.($task?->status ?? 'not in pipeline').']'
                );
            }
        }

        $this->command?->info('Test orders parked at a station:');
        foreach ($made as $line) {
            $this->command?->line($line);
        }
        $this->command?->line('');
        $this->command?->info('Open Stations, start the matching station on one of these, then use');
        $this->command?->info('"Fill the job order sheet" when you finish it.');
    }

    /** Remove the previous run's orders so the test starts clean. */
    private function clearPrevious(): void
    {
        $orders = ProductionOrder::where('order_number', 'like', self::PREFIX.'%')->get();

        foreach ($orders as $order) {
            $order->tasks()->each(function ($task) {
                $task->files()->delete();
                $task->delete();
            });
            $order->messages()->each(function ($message) {
                $message->files()->delete();
                $message->mentions()->delete();
                $message->delete();
            });
            \App\Models\StationSession::where('production_order_id', $order->id)->delete();
            $order->payments()->delete();
            $order->items()->delete();
            $order->documents()->delete();
            $order->jobOrder?->delete();
            $order->delete();
        }

        if ($orders->isNotEmpty()) {
            $this->command?->line('Cleared '.$orders->count().' order(s) from the last run.');
        }
    }

    private function makeOrder(User $sales, string $stopBefore, int $n, int $i): ProductionOrder
    {
        $order = ProductionOrder::create([
            'order_number' => self::PREFIX.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'customer_name' => $stopBefore === 'Sewing' ? "Sewing Test $i" : "QC Test $i",
            'product_type' => 'round_neck',
            'quantity' => 40,
            'unit_price' => 450,
            'total_price' => 18000,
            'due_date' => now()->addDays(10),
            'created_by' => $sales->id,
            'status' => 'active',
        ]);

        $order->items()->create(['size' => 'M', 'quantity' => 25]);
        $order->items()->create(['size' => 'L', 'quantity' => 15]);

        // The SPEC only. Everything the station is responsible for stays blank
        // on purpose — that is the thing being tested.
        $order->jobOrder()->create([
            'status' => 'sent_to_artist',
            'created_by' => $sales->id,
            'print_type' => 'full_sublimation',
            'printer' => 'atexco',
            'fabric' => 'Dri-fit micro mesh',
            'free_logo_sticker' => 'IC round sticker',
            'fb_viber_gc' => 'Test Team GC',
            'neck' => 'Printed ribbings',
            'neck_size' => '2.5 inches',
            'cuff_arm_sleeves' => 'Tupi finish',
            'cuff_size' => '3 inches',
            'neck_label' => 'IC flat bed',
            'bottom_hem' => 'Straight hem',
            'extra_seam_label' => 'Shoulder taping',
            'packaging' => 'One piece per plastic',
            'special_instructions' => 'TEST ORDER — for trying the station sheet by hand.',
        ]);

        $order->refresh()->rebuildPipeline([], 'laser');

        // Walk it up to, but not into, the step being tested.
        foreach ($order->fresh()->tasks()->orderBy('sequence')->get() as $task) {
            if ($task->department === $stopBefore) {
                break;
            }

            if (! in_array($task->status, ['complete', 'cancelled'], true)) {
                $task->forceComplete();
            }
        }

        return $order->fresh();
    }
}
