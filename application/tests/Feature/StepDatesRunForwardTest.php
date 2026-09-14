<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A step is never due before the step in front of it.
 *
 * Nine steps across five live orders are dated earlier than their own
 * predecessor - a tech pack wanted before the final mockup it is written
 * from, a pairing wanted before the laser cutting that feeds it. A board
 * showing that cannot be worked from: the thing at the top of the list is not
 * the thing to do next.
 *
 * There are two ways a date gets written, and this pins down which one can do
 * it: scheduleStepDeadlines, which writes the whole schedule at once, and
 * fillMissingStepDeadlines, which fills only the blanks and leaves everything
 * else alone.
 */
class StepDatesRunForwardTest extends TestCase
{
    use RefreshDatabase;

    /** Every dated step, in sequence, must be no earlier than the one before. */
    private function assertRunsForward(ProductionOrder $order, string $because): void
    {
        $dated = $order->tasks()->orderBy('sequence')->get()
            ->filter(fn (Task $t) => $t->due_at !== null)
            ->values();

        $backwards = [];

        for ($i = 1; $i < $dated->count(); $i++) {
            if ($dated[$i]->due_at->lt($dated[$i - 1]->due_at)) {
                $backwards[] = sprintf('step %d (%s, %s) is before step %d (%s, %s)',
                    $dated[$i]->sequence, $dated[$i]->department, $dated[$i]->due_at->format('M j H:i'),
                    $dated[$i - 1]->sequence, $dated[$i - 1]->department, $dated[$i - 1]->due_at->format('M j H:i'));
            }
        }

        $this->assertSame([], $backwards, $because."\n  ".implode("\n  ", $backwards));
    }

    private function order(bool $skipSample): ProductionOrder
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $order = ProductionOrder::createJobOrder([
            'order_number' => 'IC2026-SD'.random_int(1000, 9999),
            'customer_name' => 'Forward Client',
            'product_type' => 'round_neck',
            'quantity' => 20,
            'unit_price' => 500,
            'due_date' => now()->addDays(20),
            'skip_sample' => $skipSample,
            'created_by' => $officer->id,
            'status' => 'active',
        ], [], null);

        return $order->refresh();
    }

    /* ---------------- the whole schedule at once ---------------- */

    public function test_a_job_with_a_sample_run_is_dated_forward(): void
    {
        $order = $this->order(skipSample: false);

        $order->scheduleStepDeadlines(now());

        $this->assertRunsForward($order, 'the full schedule dated a step before its predecessor');
    }

    public function test_a_job_that_skips_the_sample_is_dated_forward(): void
    {
        $order = $this->order(skipSample: true);

        $order->scheduleStepDeadlines(now());

        $this->assertRunsForward($order, 'the full schedule dated a step before its predecessor');
    }

    /* ---------------- filling in the blanks ---------------- */

    /**
     * The one that matters. Steps born blank get a date from
     * fillMissingStepDeadlines, and a blank placed carelessly is exactly how a
     * tech pack ends up wanted before the mockup it is written from.
     */
    public function test_filling_one_blank_in_the_middle_keeps_the_run_forward(): void
    {
        $order = $this->order(skipSample: false);
        $order->scheduleStepDeadlines(now());

        // The tech pack, which is the step the live data has wrong.
        $blank = $order->tasks()->orderBy('sequence')->get()->get(2);
        $blank->update(['due_at' => null]);

        $order->fresh()->fillMissingStepDeadlines();

        $this->assertRunsForward($order, 'filling a blank dated it before the step in front of it');
    }

    public function test_filling_several_blanks_keeps_the_run_forward(): void
    {
        $order = $this->order(skipSample: false);
        $order->scheduleStepDeadlines(now());

        foreach ([1, 2, 5, 6] as $i) {
            $order->tasks()->orderBy('sequence')->get()->get($i)?->update(['due_at' => null]);
        }

        $order->fresh()->fillMissingStepDeadlines();

        $this->assertRunsForward($order, 'filling several blanks put them out of order');
    }

    /** A blank at the very front has no earlier neighbour to sit after. */
    public function test_filling_the_first_step_keeps_the_run_forward(): void
    {
        $order = $this->order(skipSample: false);
        $order->scheduleStepDeadlines(now());

        $order->tasks()->orderBy('sequence')->first()->update(['due_at' => null]);

        $order->fresh()->fillMissingStepDeadlines();

        $this->assertRunsForward($order, 'filling the first step put it after the second');
    }

    /** And one at the very end has no later neighbour to sit before. */
    public function test_filling_the_last_step_keeps_the_run_forward(): void
    {
        $order = $this->order(skipSample: false);
        $order->scheduleStepDeadlines(now());

        $order->tasks()->orderBy('sequence')->get()->last()->update(['due_at' => null]);

        $order->fresh()->fillMissingStepDeadlines();

        $this->assertRunsForward($order, 'filling the last step put it before the one in front of it');
    }

    /** Every step blank at once is the state a rebuilt pipeline is born in. */
    public function test_filling_a_wholly_undated_pipeline_runs_forward(): void
    {
        $order = $this->order(skipSample: false);

        $order->tasks()->update(['due_at' => null]);

        $order->fresh()->fillMissingStepDeadlines();

        $this->assertRunsForward($order, 'a pipeline dated from nothing came out in the wrong order');
    }
}
