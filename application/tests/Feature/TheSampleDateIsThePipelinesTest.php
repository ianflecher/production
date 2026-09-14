<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One sample date, not two that agree most of the time.
 *
 * The badge on the order and the last step of the sample run were worked out
 * separately from the same inputs. Two formulas for one date drift, and these
 * had already: one read the bare three-day constant while the schedule read
 * the method that knows about jerseys, and a jersey's badge and its own steps
 * disagreed by a day.
 *
 * So the date is read off the pipeline, and the formula sits behind it for
 * the case where the pipeline has not been dated. Since the schedule pins its
 * last sample step to exactly what the formula would have said, the ordinary
 * order is unchanged - and the two places they could still part company are
 * settled:
 *
 *   a rush job, where the schedule clamps its steps to a client deadline
 *   inside the sample window and the formula did not, so the sample was never
 *   called late until the whole job already was;
 *
 *   and a deadline somebody moved by hand, where the badge went on insisting
 *   on the original day.
 */
class TheSampleDateIsThePipelinesTest extends TestCase
{
    use RefreshDatabase;

    private function order(array $extra = []): ProductionOrder
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $order = ProductionOrder::create(array_merge([
            'order_number' => 'IC2026-SD'.random_int(1000, 9999),
            'customer_name' => 'Sample Client',
            'product_type' => 'round_neck',
            'quantity' => 20,
            'unit_price' => 500,
            'due_date' => now()->addWeeks(3),
            'created_by' => $officer->id,
            'status' => 'active',
        ], $extra));

        $order->items()->create(['size' => 'M', 'quantity' => 20]);
        $order->refresh()->buildPipeline([], 'manual');

        return $order->refresh();
    }

    /** Confirmed money is what starts both clocks, in the same moment. */
    private function paid(ProductionOrder $order, string $when = '2026-09-01 09:00:00'): ProductionOrder
    {
        Payment::create([
            'production_order_id' => $order->id,
            'amount' => 5000,
            'status' => 'confirmed',
            'confirmed_at' => $when,
        ]);

        $order = $order->fresh();
        $order->scheduleStepDeadlines();

        return $order->fresh();
    }

    /**
     * Sorted in PHP on purpose. The tasks relation carries orderBy('sequence')
     * and an orderByDesc added to the query is appended to it, not instead of
     * it - which is the exact trap the model had, and a test helper repeating
     * it would have agreed with a bug rather than caught one.
     */
    private function lastSampleStep(ProductionOrder $order)
    {
        return $order->tasks()
            ->where('stage', '<', ProductionOrder::STAGE_MASS_PRODUCTION)
            ->whereNotNull('due_at')
            ->get()
            ->sortByDesc('due_at')
            ->first();
    }

    /**
     * The ordinary order is unchanged, which is the point: the schedule pins
     * its last sample step to the day the formula names, so reading it back
     * gives the same answer.
     */
    public function test_the_badge_and_the_last_sample_step_are_the_same_day(): void
    {
        $order = $this->paid($this->order());

        $badge = $order->computeSampleDueDate();
        $step = $this->lastSampleStep($order);

        $this->assertNotNull($badge);
        $this->assertNotNull($step, 'the sample run was never dated');
        $this->assertSame($step->due_at->toDateString(), $badge->toDateString());

        // And it is still three days from the confirmed payment.
        $this->assertSame('2026-09-04', $badge->toDateString());
    }

    /** A jersey still gets its fourth day, from whichever end you read it. */
    public function test_a_jersey_still_gets_the_longer_window(): void
    {
        $order = $this->paid($this->order(['product_type' => 'riding_jersey']));

        $this->assertSame('2026-09-05', $order->computeSampleDueDate()->toDateString());
    }

    /**
     * The case this was built for: a leader moves the last sample step, and
     * the badge follows instead of arguing with them.
     */
    public function test_a_deadline_moved_by_hand_moves_the_sample_date(): void
    {
        $order = $this->paid($this->order());

        $step = $this->lastSampleStep($order);
        $step->update(['due_at' => \Illuminate\Support\Carbon::parse('2026-09-09')->endOfDay()]);

        $this->assertSame('2026-09-09', $order->fresh()->computeSampleDueDate()->toDateString(),
            'the badge went on insisting on the day nobody wants any more');
    }

    /**
     * A rush job. The schedule clamps the sample run to a client deadline
     * that lands inside the sample window; the badge used to sit after the
     * promise, so the sample was never late until the job already was.
     */
    public function test_a_rush_job_does_not_promise_a_sample_after_the_delivery(): void
    {
        $order = $this->order(['due_date' => \Illuminate\Support\Carbon::parse('2026-09-02')]);
        $order = $this->paid($order);

        $badge = $order->computeSampleDueDate();

        $this->assertNotNull($badge);
        $this->assertTrue($badge->lessThanOrEqualTo($order->due_date->copy()->endOfDay()),
            'the sample was promised for after the client already has the goods');
        $this->assertSame('2026-09-02', $badge->toDateString());
    }

    /**
     * And the formula is still there for an order the schedule gave up on.
     * scheduleStepDeadlines() does nothing without a client due date, so the
     * steps are undated and the sample still has a promise of its own.
     */
    public function test_an_order_with_no_client_due_date_falls_back_to_the_formula(): void
    {
        $order = $this->order();
        $order->forceFill(['due_date' => null])->save();
        $order = $this->paid($order->fresh());

        $this->assertNull($this->lastSampleStep($order), 'the schedule should not have dated anything');
        $this->assertSame('2026-09-04', $order->computeSampleDueDate()->toDateString());
    }

    /**
     * A job that was never cleared to start has no sample date, even when its
     * steps somehow carry dates.
     *
     * scheduleStepDeadlines() falls back to now() when there is no confirmed
     * payment, so a job can end up with dated steps without ever having been
     * paid for - there is one on the shop's own board. Reading those back
     * would hand it a deadline for a clock that never started, and then call
     * it late against that deadline.
     */
    public function test_a_job_that_was_never_paid_for_has_no_sample_date(): void
    {
        $order = $this->order();

        // Dated without a payment, the way that job got its dates.
        $order->scheduleStepDeadlines(\Illuminate\Support\Carbon::parse('2026-09-01 09:00:00'));
        $order = $order->fresh();

        $this->assertNotNull($this->lastSampleStep($order), 'the steps should be dated for this test to mean anything');
        $this->assertFalse($order->hasDownpayment());
        $this->assertNull($order->computeSampleDueDate(),
            'a job nobody has paid for was given a sample deadline to be late against');
        $this->assertFalse($order->sampleOverdue());
    }

    /** A waived deposit IS clearance, so a sponsored job does get a date. */
    public function test_a_sponsored_job_with_a_waived_deposit_still_gets_one(): void
    {
        $order = $this->order(['downpayment_waived' => true]);
        $order->scheduleStepDeadlines(\Illuminate\Support\Carbon::parse('2026-09-01 09:00:00'));
        $order = $order->fresh();

        $this->assertTrue($order->hasDownpayment());
        $this->assertSame('2026-09-04', $order->computeSampleDueDate()->toDateString());
    }

    /** An order set to skip the sample has no sample date from either source. */
    public function test_skipping_the_sample_still_means_no_date(): void
    {
        $order = $this->paid($this->order(['skip_sample' => true]));

        $this->assertNull($order->computeSampleDueDate());
    }

    /**
     * Reading it must not cost a query per order. The page that asks loads
     * the tasks already, and this reads them rather than asking again.
     */
    public function test_it_reads_the_tasks_already_loaded(): void
    {
        $order = $this->paid($this->order());

        // Both, because the answer needs both: whether the job was cleared to
        // start, and when its sample run is wanted. The page that asks loads
        // each of them already.
        $order->load('tasks', 'payments');

        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();

        $order->computeSampleDueDate();

        $count = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertSame(0, $count, 'the sample date went back to the database for tasks it already had');
    }
}
