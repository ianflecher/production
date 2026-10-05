<?php

namespace Tests\Feature;

use App\Models\JobOrder;
use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Manual cutting has no station, so it does not wait for one.
 *
 * It is done by hand at a table, by people with no computer in front of them.
 * Nothing could ever mark it finished, so the job stopped on a step that was,
 * in the room, already done - eight were sitting like that when this was
 * written. It is listed under the laser cutting team because that is the
 * nearest desk, which made it look reachable without being so: the laser
 * operator is at their own machine, not standing over somebody else's table.
 *
 * Laser cutting is untouched. That one has a screen.
 */
class ManualCuttingDoesNotWaitForAStationTest extends TestCase
{
    use RefreshDatabase;

    private function order(string $cutting): ProductionOrder
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-0'.random_int(1000, 9999),
            'customer_name' => 'Cutting Co',
            'product_type' => 'round_neck',
            'quantity' => 20,
            'due_date' => now()->addWeeks(2),
            'created_by' => $officer->id,
            'status' => 'active',
        ]);

        $order->jobOrder()->create([
            'status' => 'sent_to_artist', 'created_by' => $officer->id,
            'print_type' => 'dtf', 'printer' => 'dtf_printer',
        ]);

        $order->refresh()->rebuildPipeline([], $cutting);

        return $order->fresh();
    }

    private function cuttingStep(ProductionOrder $order, string $label): Task
    {
        $task = $order->tasks()->where('department', $label)->first();

        $this->assertNotNull($task, $label.' is not on the pipeline');

        return $task;
    }

    /* ---------------- the step itself ---------------- */

    public function test_manual_cutting_is_a_step_with_no_station(): void
    {
        $task = $this->cuttingStep($this->order('manual'), 'Manual cutting');

        $this->assertTrue($task->hasNoStation());
    }

    public function test_laser_cutting_has_one(): void
    {
        $task = $this->cuttingStep($this->order('laser'), 'Laser cutting');

        $this->assertFalse($task->hasNoStation());
    }

    /* ---------------- what happens when it opens ---------------- */

    /** Released and finished in the same breath, so the job keeps moving. */
    public function test_manual_cutting_passes_the_moment_it_opens(): void
    {
        $order = $this->order('manual');
        $task = $this->cuttingStep($order, 'Manual cutting');

        $order->unlockStage($task->stage);

        $this->assertSame('complete', $task->fresh()->status,
            'the job is still sitting on a step nobody can mark off');
    }

    /** And the step after it is open, which is the whole point. */
    public function test_the_next_step_opens_behind_it(): void
    {
        $order = $this->order('manual');
        $cut = $this->cuttingStep($order, 'Manual cutting');

        $order->unlockStage($cut->stage);

        $after = $order->tasks()
            ->where('stage', $cut->stage)
            ->where('sequence', '>', $cut->sequence)
            ->orderBy('sequence')
            ->first();

        if ($after) {
            $this->assertNotSame('todo', $after->fresh()->status,
                'the next station was never told the cutting was done');
        }

        $this->assertSame('complete', $cut->fresh()->status);
    }

    /** Laser cutting still waits for the person at the machine. */
    public function test_laser_cutting_still_waits_to_be_marked_off(): void
    {
        $order = $this->order('laser');
        $task = $this->cuttingStep($order, 'Laser cutting');

        $order->unlockStage($task->stage);

        $this->assertSame('ready', $task->fresh()->status,
            'laser cutting completed itself, and it has a screen to do it from');
    }

    /** Nobody is named for work the system only assumed. */
    public function test_nobody_is_recorded_against_it(): void
    {
        $order = $this->order('manual');
        $task = $this->cuttingStep($order, 'Manual cutting');

        $order->unlockStage($task->stage);

        $this->assertNull($task->fresh()->approved_by,
            'somebody got the credit for a cut they never saw');
    }

    /** Passing one that is already done changes nothing. */
    public function test_passing_a_finished_step_is_harmless(): void
    {
        $order = $this->order('manual');
        $task = $this->cuttingStep($order, 'Manual cutting');

        $order->unlockStage($task->stage);
        $first = $task->fresh()->approved_at;

        $task->fresh()->passWithoutAStation();

        $this->assertEquals($first, $task->fresh()->approved_at);
    }
}
