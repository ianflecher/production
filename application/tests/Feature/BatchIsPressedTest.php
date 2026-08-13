<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The batch gets pressed, not just printed.
 *
 * Stages 10-15 were written as "the rest of the batch goes through the same
 * line the sample did" — but the press was left out of that copy. The sample
 * was printed and pressed; the batch was printed and sent straight to cutting.
 * On a sublimation job the transfer has to go onto the cloth before anything
 * can be cut, so the shop pressed it anyway, off the books: never timed, and
 * never able to show up as the step holding an order up.
 */
class BatchIsPressedTest extends TestCase
{
    use RefreshDatabase;

    /** An order with a full pipeline built, pressing with the given press. */
    private function pipeline(string $press = 'roller_press', bool $skipSample = false): Collection
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-0'.random_int(1000, 9999),
            'customer_name' => 'Press Co',
            'product_type' => 'round_neck',
            'quantity' => 55,
            'due_date' => now()->addWeeks(3),
            'created_by' => $sales->id,
            'status' => 'active',
            'skip_sample' => $skipSample,
        ]);

        $order->jobOrder()->create([
            'status' => 'sent_to_artist', 'created_by' => $sales->id,
            'print_type' => 'full_sublimation', 'printer' => 'atexco', 'press' => $press,
        ]);

        $order->refresh()->rebuildPipeline([], 'laser');

        return $order->fresh()->tasks()->orderBy('sequence')->get();
    }

    public function test_the_batch_run_has_a_press_step(): void
    {
        $tasks = $this->pipeline();

        // Stage 10 is the batch print. The press belongs with it, exactly as
        // the sample's press sits with the Printer at stage 3.
        $this->assertTrue(
            $tasks->where('stage', 10)->contains(fn ($t) => $t->department === 'Roller press'),
            'the batch is printed at stage 10 and must be pressed there too'
        );
    }

    public function test_the_press_runs_on_both_the_sample_and_the_batch(): void
    {
        $presses = $this->pipeline()->where('department', 'Roller press');

        $this->assertCount(2, $presses, 'one press for the sample, one for the batch');
        $this->assertSame([3, 10], $presses->pluck('stage')->sort()->values()->all());
    }

    public function test_the_batch_press_waits_for_the_batch_to_be_printed(): void
    {
        $tasks = $this->pipeline();
        $order = $tasks->first()->order;

        // Walk everything up to Mass production, leaving it open.
        foreach ($tasks as $t) {
            if ($t->department === 'Mass production') {
                break;
            }
            if (! in_array($t->status, ['complete', 'cancelled'], true)) {
                $t->forceComplete();
            }
        }

        $press = $order->fresh()->tasks()->where('stage', 10)
            ->where('department', 'Roller press')->firstOrFail();

        $this->assertNotSame('ready', $press->status,
            'nothing to press until the batch has been printed');

        $order->fresh()->tasks()->where('department', 'Mass production')->first()->forceComplete();

        $this->assertSame('ready', $order->fresh()->tasks()->where('stage', 10)
            ->where('department', 'Roller press')->first()->status,
            'once the batch is printed the press is the next thing to happen');
    }

    public function test_the_batch_is_pressed_before_it_is_cut(): void
    {
        $tasks = $this->pipeline();

        $press = $tasks->where('stage', 10)->firstWhere('department', 'Roller press');
        $cutting = $tasks->where('stage', 11)->firstWhere('department', 'Laser cutting');

        $this->assertLessThan($cutting->sequence, $press->sequence,
            'you cannot cut cloth the transfer has not been pressed onto yet');
    }

    public function test_an_order_that_skips_the_sample_is_still_pressed(): void
    {
        $tasks = $this->pipeline(skipSample: true);

        // Skipping the sample drops stages 5-9, not the stage-3 supply run —
        // such an order still has BOTH prints (Printer at 3, Mass production
        // at 10), so it gets a press against each. One press per print is the
        // rule; the sample run is not what decides it.
        $this->assertSame(
            $tasks->whereIn('department', ['Printer', 'Mass production'])->pluck('stage')->sort()->values()->all(),
            $tasks->where('department', 'Roller press')->pluck('stage')->sort()->values()->all(),
            'every print step must have a press beside it'
        );
    }

    /**
     * The path orders actually take.
     *
     * A pipeline is laid down when the order is taken, before anyone has said
     * which press the job needs. Filling in the job order later does not
     * rebuild the line — it SWAPS the routing steps into the existing one, a
     * separate branch that had its own copy of the press logic. Fixing only
     * the full build left every real order untouched: IC2026-00084 was created
     * a minute after the fix and still came out with no press on the batch.
     */
    public function test_a_press_chosen_after_the_order_was_taken_reaches_the_batch(): void
    {
        $tasks = $this->pipeline('heat_press');
        $order = $tasks->first()->order;

        // The job order is filled in later and the routing is swapped in.
        $order->jobOrder->update(['press' => 'roller_press']);
        $order->fresh()->rebuildPipeline([], 'manual');

        $after = $order->fresh()->tasks;

        $this->assertTrue(
            $after->where('stage', 10)->contains(fn ($t) => $t->department === 'Roller press'),
            'the press chosen on the job order must reach the batch, not just the sample'
        );
        $this->assertEmpty($after->where('department', 'Heat press'),
            'the press that was replaced must not be left behind on either run');
    }

    public function test_embroidery_is_not_dragged_into_the_batch_print(): void
    {
        // Embroidery goes on the sewn garment, not on flat printed cloth —
        // the same reason it is not at stage 3 with the other decoration.
        $tasks = $this->pipeline('embroidery');

        $this->assertEmpty(
            $tasks->where('stage', 10)->where('department', 'Embroidery'),
            'embroidery runs after sewing, not next to the printer'
        );
    }
}
