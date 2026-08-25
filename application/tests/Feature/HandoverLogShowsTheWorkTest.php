<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\StationSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The handover log says what was done, not just who was on the machine.
 *
 * A sewing stint leaves a line per seam — what was run and who ran it — and a
 * quality check leaves what was looked at and what was found. Both are written
 * at the station and both were only readable by opening the job. The log is
 * where somebody looks to see how a garment was made, so it carries them.
 */
class HandoverLogShowsTheWorkTest extends TestCase
{
    use RefreshDatabase;

    private function orderOnTheFloor(): ProductionOrder
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-SEWN', 'customer_name' => 'Seam Co',
            'product_type' => 'round_neck', 'quantity' => 20,
            'due_date' => now()->addWeek(), 'created_by' => $sales->id, 'status' => 'active',
        ]);

        $order->jobOrder()->create([
            'status' => 'sent_to_artist', 'created_by' => $sales->id,
            'print_type' => 'dtf', 'printer' => 'dtf_printer',
        ]);

        return $order->refresh();
    }

    private function stint(ProductionOrder $order, string $station, User $who): StationSession
    {
        return StationSession::create([
            'station' => $station,
            'user_id' => $who->id,
            'production_order_id' => $order->id,
            'started_at' => now()->subHour(),
            'ended_at' => now(),
            'end_reason' => 'finished',
        ]);
    }

    public function test_a_sewing_stint_carries_the_seams_that_were_run(): void
    {
        $order = $this->orderOnTheFloor();
        $sewer = User::factory()->create(['job_role' => 'Sewing', 'is_active' => true]);

        $order->jobOrder->update([
            'sewing_log' => [
                ['work' => 'Neckbond', 'name' => 'Geneline'],
                ['work' => 'Topping side', 'name' => 'Marites'],
            ],
            'sewer_notes' => 'Machine 3 skipped, moved to 5',
        ]);

        $done = $this->stint($order->refresh(), 'sewing_1', $sewer)->workDone();

        $this->assertSame([
            'Neckbond — Geneline',
            'Topping side — Marites',
            'Machine 3 skipped, moved to 5',
        ], $done);
    }

    public function test_a_quality_stint_carries_what_was_checked(): void
    {
        $order = $this->orderOnTheFloor();
        $checker = User::factory()->create(['job_role' => 'Quality Control', 'is_active' => true]);

        $order->jobOrder->update([
            'qc_notes' => 'Mockup matched, two loose threads trimmed',
            'qc_checked_by' => 'Rowena',
        ]);

        $this->assertSame(
            ['Mockup matched, two loose threads trimmed — Rowena'],
            $this->stint($order->refresh(), 'qc_1', $checker)->workDone()
        );
    }

    public function test_a_seam_with_no_name_still_says_what_was_done(): void
    {
        $order = $this->orderOnTheFloor();
        $sewer = User::factory()->create(['job_role' => 'Sewing', 'is_active' => true]);

        $order->jobOrder->update([
            'sewing_log' => [
                ['work' => 'Flatbed', 'name' => ''],
                // A blank line on the sheet is not a seam.
                ['work' => '', 'name' => 'Nobody'],
            ],
        ]);

        $this->assertSame(['Flatbed'], $this->stint($order->refresh(), 'sewing_2', $sewer)->workDone());
    }

    public function test_a_station_that_records_nothing_says_nothing(): void
    {
        // Cutting and the presses keep no record of their own, so the column is
        // empty for them rather than repeating somebody else's work.
        $order = $this->orderOnTheFloor();
        $cutter = User::factory()->create(['job_role' => 'Laser Cutting', 'is_active' => true]);

        $order->jobOrder->update(['sewing_log' => [['work' => 'Neckbond', 'name' => 'Geneline']]]);

        $this->assertSame([], $this->stint($order->refresh(), 'laser_1', $cutter)->workDone());
    }

    public function test_the_log_on_the_board_shows_it(): void
    {
        $order = $this->orderOnTheFloor();
        $sewer = User::factory()->create(['job_role' => 'Sewing', 'is_active' => true]);

        $order->jobOrder->update([
            'sewing_log' => [['work' => 'Neckbond', 'name' => 'Geneline']],
        ]);

        $this->stint($order->refresh(), 'sewing_1', $sewer);

        $this->actingAs($sewer)->get('/stations')
            ->assertOk()
            ->assertSee('What they did')
            ->assertSee('Neckbond — Geneline');
    }
}
