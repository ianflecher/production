<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sample has a deadline of its own.
 *
 * The order carried one date and it was the day the finished batch had to be
 * out of the door. The sample had none, so a sample that sat for a week was
 * only noticed when the batch behind it ran short - and the whole delay landed
 * on the floor at the end, where there is no time left to absorb it.
 *
 * Three days from the confirmed payment for every product. The batch keeps
 * the order due date: that promise to the client has not changed.
 */
class SampleHasItsOwnDeadlineTest extends TestCase
{
    use RefreshDatabase;

    private function order(string $productType, bool $skipSample = false): ProductionOrder
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        return ProductionOrder::create([
            'order_number' => 'IC2026-DUE'.random_int(1000, 9999),
            'customer_name' => 'Deadline Client',
            'product_type' => $productType,
            'quantity' => 20,
            'due_date' => now()->addWeeks(3),
            'created_by' => $officer->id,
            'status' => 'active',
            'skip_sample' => $skipSample,
        ]);
    }

    private function paidOn(ProductionOrder $order, string $when): ProductionOrder
    {
        Payment::create([
            'production_order_id' => $order->id,
            'amount' => 5000,
            'status' => 'confirmed',
            'confirmed_at' => $when,
        ]);

        return $order->fresh();
    }

    public function test_a_shirt_gets_three_days_from_the_confirmed_payment(): void
    {
        $order = $this->paidOn($this->order('round_neck'), '2026-09-01 09:00:00');

        $this->assertSame(3, $order->sampleLeadDays());
        $this->assertSame('2026-09-04', $order->computeSampleDueDate()->toDateString());
    }

    public function test_a_riding_jersey_uses_the_same_three_day_sample_window(): void
    {
        $order = $this->paidOn($this->order('riding_jersey'), '2026-09-01 09:00:00');

        $this->assertSame(3, $order->sampleLeadDays());
        $this->assertSame('2026-09-04', $order->computeSampleDueDate()->toDateString());
    }

    public function test_the_batch_keeps_the_orders_own_due_date(): void
    {
        // Two dates, two promises: the sample is the shop's, the batch is the
        // client's, and the split must not move the one the client was given.
        $order = $this->paidOn($this->order('round_neck'), '2026-09-01 09:00:00');
        $due = $order->due_date->toDateString();

        $order->applySampleDueDate();

        $order = $order->fresh();
        $this->assertSame($due, $order->due_date->toDateString());
        $this->assertSame('2026-09-04', $order->sample_due_date->toDateString());
    }

    public function test_an_unpaid_order_has_not_started_its_clock(): void
    {
        $order = $this->order('round_neck');

        $this->assertNull($order->computeSampleDueDate());
        $this->assertNull($order->applySampleDueDate());
        $this->assertNull($order->fresh()->sample_due_date);
    }

    public function test_an_order_that_skips_the_sample_has_no_sample_to_be_late_for(): void
    {
        $order = $this->paidOn($this->order('round_neck', skipSample: true), '2026-09-01 09:00:00');

        $this->assertNull($order->computeSampleDueDate());
        $this->assertFalse($order->sampleOverdue());
    }

    public function test_a_sample_past_its_day_is_overdue(): void
    {
        $order = $this->paidOn($this->order('round_neck'), now()->subDays(10)->toDateTimeString());
        $order->applySampleDueDate();

        $this->assertTrue($order->fresh()->sampleOverdue());
    }

    public function test_a_sample_still_inside_its_days_is_not_overdue(): void
    {
        $order = $this->paidOn($this->order('round_neck'), now()->toDateTimeString());
        $order->applySampleDueDate();

        $this->assertFalse($order->fresh()->sampleOverdue());
    }

    public function test_the_order_page_says_when_the_sample_is_due(): void
    {
        // Written down where the officer looks, next to the date the client
        // was given - the two are different promises.
        $order = $this->paidOn($this->order('round_neck'), '2026-09-01 09:00:00');
        $order->applySampleDueDate();

        // Their own order: an account officer only sees the jobs they took.
        $this->actingAs(User::find($order->created_by))->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('Sample due')
            ->assertSee('Sep 4, 2026');
    }

    public function test_confirming_the_payment_writes_the_sample_date_down(): void
    {
        // The whole point of hanging it off the payment: nobody has to
        // remember to set it.
        $finance = User::factory()->create(['job_role' => User::ROLE_FINANCE, 'is_active' => true]);
        $order = $this->order('round_neck');

        $payment = Payment::create([
            'production_order_id' => $order->id,
            'amount' => 5000,
            'status' => 'pending',
        ]);

        $this->actingAs($finance)
            ->post(route('finance.confirm', $payment), ['confirmed_name' => 'Marites'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $order = $order->fresh();
        $this->assertNotNull($order->sample_due_date, 'the sample never got a date');
        $this->assertSame(
            now()->startOfDay()->addDays(3)->toDateString(),
            $order->sample_due_date->toDateString()
        );
    }
}
