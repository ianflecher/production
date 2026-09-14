<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A step with no deadline cannot be late, and nobody knows it is waiting.
 *
 * The schedule was written once - at the moment the downpayment cleared - and
 * never again. Anything that changed the pipeline afterwards left steps with
 * no date at all, and an audit of the shop found eighty-two of them across
 * twelve jobs: whole orders where every box in the pipeline was blank.
 *
 * Three ways in, all of them ordinary:
 *
 *   a sibling order written under a job number whose downpayment had already
 *   cleared, so the one scheduling moment had been and gone before the order
 *   existed - which is every order after the first on a shared job number;
 *
 *   a pipeline rebuilt because a tech pack changed, whose new steps are born
 *   blank;
 *
 *   and a sponsored job, waived rather than paid, which never has a payment
 *   to confirm and so never reaches the moment at all.
 *
 * The gaps are filled and nothing else is touched. A date somebody typed into
 * the pipeline is the whole point of that box being there.
 */
class EveryStepGetsADeadlineTest extends TestCase
{
    use RefreshDatabase;

    private function officer(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
    }

    private function order(array $extra = []): ProductionOrder
    {
        $order = ProductionOrder::createJobOrder(array_merge([
            'order_number' => 'IC2026-FD'.random_int(1000, 9999),
            'customer_name' => 'Deadline Client',
            'product_type' => 'round_neck',
            'quantity' => 20,
            'unit_price' => 500,
            'due_date' => now()->addWeeks(3),
            'created_by' => $this->officer()->id,
            'status' => 'active',
        ], $extra), [], null);

        return $order->refresh();
    }

    private function undated(ProductionOrder $order): int
    {
        return $order->tasks()->whereNull('due_at')->where('status', '!=', 'cancelled')->count();
    }

    /**
     * The sponsored job. Waived means cleared, and there is no payment coming
     * to write the schedule later - so it is written on the way in.
     */
    public function test_a_waived_order_is_scheduled_the_moment_it_is_created(): void
    {
        $order = $this->order(['downpayment_waived' => true]);

        $this->assertTrue($order->hasDownpayment());
        $this->assertGreaterThan(0, $order->tasks()->count(), 'no pipeline was built');
        $this->assertSame(0, $this->undated($order),
            'a waived job was created with a due date and no deadlines on any of its steps');
    }

    /**
     * And a job still waiting on its money is NOT scheduled. Its clock has not
     * started, and dating it would hand it deadlines to be late against for
     * work nobody has asked for yet.
     */
    public function test_an_unpaid_order_is_left_alone(): void
    {
        $order = $this->order();

        $this->assertFalse($order->hasDownpayment());
        $this->assertSame($order->tasks()->count(), $this->undated($order),
            'a job nobody has paid for was given deadlines');
    }

    /** And it gets them when the money lands, the way it always did. */
    public function test_the_money_landing_still_writes_the_schedule(): void
    {
        $order = $this->order();

        Payment::create([
            'production_order_id' => $order->id,
            'amount' => 5000,
            'status' => 'confirmed',
            'confirmed_at' => now(),
        ]);

        $order->fresh()->scheduleStepDeadlines();

        $this->assertSame(0, $this->undated($order->fresh()));
    }

    /**
     * The case the shop is actually in: a job already running, with blanks.
     */
    public function test_filling_the_blanks_leaves_every_existing_date_alone(): void
    {
        $order = $this->order(['downpayment_waived' => true]);

        $kept = $order->tasks()->orderBy('sequence')->first();
        $typed = Carbon::parse('2026-12-25')->endOfDay();
        $kept->update(['due_at' => $typed]);

        // Blank three others, the way a rebuild leaves them.
        $blanked = $order->tasks()->orderBy('sequence')->skip(1)->take(3)->get();
        Task::whereIn('id', $blanked->pluck('id'))->update(['due_at' => null]);

        $filled = $order->fresh()->fillMissingStepDeadlines();

        $this->assertSame(3, $filled, 'it should have filled exactly the three blanks');
        $this->assertSame($typed->toDateTimeString(), $kept->fresh()->due_at->toDateTimeString(),
            'a date somebody typed was overwritten by a gap-filling pass');

        foreach ($blanked as $step) {
            $this->assertNotNull($step->fresh()->due_at, $step->department.' was left blank');
        }
    }

    /**
     * A filled step never lands after the step that follows it.
     *
     * The first attempt placed each blank where the full schedule would have
     * put it, which ignores what the steps around it already say - and on the
     * shop's own jobs it produced Pairing due before the cutting it waits on,
     * and mass production due before the tech pack. A gap is only ever filled
     * inside the space its neighbours leave.
     */
    public function test_a_filled_step_never_jumps_ahead_of_the_one_after_it(): void
    {
        $order = $this->order(['downpayment_waived' => true]);

        $steps = $order->tasks()->get()->sortBy([['stage', 'asc'], ['sequence', 'asc']])->values();
        $this->assertGreaterThan(4, $steps->count());

        // A later step pinned tight up against the first one, and the two
        // between them blanked. Placed by window position those blanks would
        // be days away and sail straight past the pin; they have to fit in
        // the two hours their neighbours actually leave.
        //
        // Pinned AFTER the step before it on purpose: a pin that is earlier
        // than its own predecessor is already out of order before anything is
        // filled, and would be testing the pin rather than the filling.
        $pinned = $steps[3];
        $pinned->update(['due_at' => $steps[0]->due_at->copy()->addHours(2)]);
        Task::whereIn('id', [$steps[1]->id, $steps[2]->id])->update(['due_at' => null]);

        $order->fresh()->fillMissingStepDeadlines();

        $inOrder = $order->fresh()->tasks()->get()
            ->sortBy([['stage', 'asc'], ['sequence', 'asc']])->values();

        $previous = null;
        foreach ($inOrder as $step) {
            if (! $step->due_at) {
                continue;
            }
            if ($previous) {
                $this->assertTrue($step->due_at->greaterThanOrEqualTo($previous->due_at),
                    $step->department.' is due before '.$previous->department.', which comes first');
            }
            $previous = $step;
        }

        $this->assertSame($steps[0]->due_at->copy()->addHours(2)->toDateTimeString(),
            $pinned->fresh()->due_at->toDateTimeString(), 'the pinned date was moved');

        // And they really did land in the gap rather than beyond it.
        foreach ([$steps[1], $steps[2]] as $wasBlank) {
            $filledAt = $wasBlank->fresh()->due_at;
            $this->assertNotNull($filledAt);
            $this->assertTrue($filledAt->betweenIncluded($steps[0]->due_at, $pinned->fresh()->due_at),
                $wasBlank->department.' was placed outside the space its neighbours left');
        }
    }

    /** Nothing to fill, nothing done - and no write either. */
    public function test_it_does_nothing_when_there_is_nothing_missing(): void
    {
        $order = $this->order(['downpayment_waived' => true]);

        $this->assertSame(0, $this->undated($order));
        $this->assertSame(0, $order->fillMissingStepDeadlines());
    }

    /** A job with no client due date has nothing to schedule against. */
    public function test_an_order_with_no_due_date_is_left_alone(): void
    {
        $order = $this->order(['downpayment_waived' => true]);
        $order->forceFill(['due_date' => null])->save();

        Task::where('production_order_id', $order->id)->update(['due_at' => null]);

        $this->assertSame(0, $order->fresh()->fillMissingStepDeadlines());
    }

    /**
     * A sibling written under a job number that already cleared. This is the
     * one that put whole orders on the board with every box blank.
     */
    public function test_a_second_order_on_a_cleared_job_number_is_scheduled_too(): void
    {
        $first = $this->order(['order_number' => 'IC2026-SHARED', 'downpayment_waived' => true]);
        $this->assertSame(0, $this->undated($first));

        // Written days later, same number, same waiver - and by then the one
        // scheduling moment has long passed.
        $second = $this->order(['order_number' => 'IC2026-SHARED', 'downpayment_waived' => true]);

        $this->assertSame(0, $this->undated($second),
            'the second order on a shared job number was created with no deadlines at all');
    }
}
