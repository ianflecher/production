<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A date on every step, not just on the order.
 *
 * "Due the 14th" tells a sewer nothing about whether they are late — there are
 * sixteen steps between the money and the door. The span from the confirmed
 * downpayment to the due date is shared out evenly, and each step carries the
 * moment it has to be finished by, with the last landing on the due date.
 */
class EveryStepCarriesItsOwnDateTest extends TestCase
{
    use RefreshDatabase;

    private function order(?\Carbon\CarbonInterface $due = null): ProductionOrder
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-DATE', 'customer_name' => 'Deadline Co',
            'product_type' => 'round_neck', 'quantity' => 20, 'unit_price' => 350,
            'due_date' => $due ?? now()->addDays(30),
            'created_by' => $sales->id, 'status' => 'active',
        ]);

        $order->items()->create(['size' => 'M', 'quantity' => 20]);
        $order->jobOrder()->create(['status' => 'draft', 'created_by' => $sales->id]);
        $order->refresh()->buildPipeline([], 'manual');

        return $order->refresh();
    }

    public function test_every_step_gets_a_date(): void
    {
        $order = $this->order();

        $this->assertGreaterThan(0, $order->scheduleStepDeadlines());

        $this->assertSame(
            0,
            $order->fresh()->tasks()->whereNull('due_at')->count(),
            'a step was left without a date'
        );
    }

    public function test_the_dates_run_in_order_and_end_on_the_due_date(): void
    {
        $order = $this->order();
        $order->scheduleStepDeadlines(now());

        $dates = $order->fresh()->tasks()->orderBy('sequence')->pluck('due_at');

        $sorted = $dates->sort()->values();
        $this->assertEquals($sorted->all(), $dates->values()->all(), 'a later step was due before an earlier one');

        // The last one lands on the due date itself.
        $this->assertSame(
            $order->due_date->toDateString(),
            $dates->last()->toDateString(),
            'the last step does not finish on the day the order is due'
        );
    }

    public function test_the_span_is_shared_evenly(): void
    {
        // Thirty days over the pipeline: the gap between one step and the next
        // is the same the whole way down.
        $start = now()->startOfDay();
        $order = $this->order($start->copy()->addDays(30));

        $order->scheduleStepDeadlines($start);

        $dates = $order->fresh()->tasks()->orderBy('sequence')->pluck('due_at')->values();

        $gaps = [];
        for ($i = 1; $i < $dates->count(); $i++) {
            $gaps[] = $dates[$i - 1]->diffInMinutes($dates[$i]);
        }

        // Rounding to the minute leaves at most a minute between the gaps.
        $this->assertLessThanOrEqual(1, max($gaps) - min($gaps), 'the steps were not shared evenly');
    }

    public function test_a_job_already_past_its_date_wants_everything_now(): void
    {
        // Late is late. Handing out dates in the past by pretending the span
        // still exists would put step one three weeks ago.
        $order = $this->order(now()->subDays(3));

        $order->scheduleStepDeadlines(now());

        $due = $order->fresh()->tasks()->pluck('due_at');

        $this->assertTrue($due->every(fn ($d) => $d->isSameDay($order->due_date)));
    }

    public function test_an_order_with_no_due_date_gets_none(): void
    {
        $order = $this->order();
        $order->update(['due_date' => null]);

        $this->assertSame(0, $order->fresh()->scheduleStepDeadlines());
    }

    public function test_a_step_is_late_only_while_it_is_unfinished(): void
    {
        $order = $this->order();
        $step = $order->tasks()->orderBy('sequence')->firstOrFail();

        $step->update(['due_at' => now()->subDay(), 'status' => 'ready']);
        $this->assertTrue($step->fresh()->isOverdue());

        // Finished work cannot be late any more — it is done.
        $step->update(['status' => 'complete']);
        $this->assertFalse($step->fresh()->isOverdue());
    }

    public function test_the_clock_starts_at_the_confirmed_payment(): void
    {
        // Not when the order was taken: an order that sat unpaid for a
        // fortnight has not used any of its time.
        $order = $this->order();
        $finance = User::factory()->create(['job_role' => User::ROLE_FINANCE, 'is_active' => true]);

        $order->payments()->create([
            'amount' => 1000, 'kind' => 'downpayment', 'paid_at' => now()->subWeeks(2),
            'confirmed_at' => now(), 'confirmed_by' => $finance->id,
        ]);

        $this->assertTrue(
            $order->fresh()->firstConfirmedPaymentAt()->isToday(),
            'the clock started when the money was claimed rather than confirmed'
        );
    }
}
