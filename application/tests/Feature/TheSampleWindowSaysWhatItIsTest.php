<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Payment;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sample gets the window the shop actually promised it.
 *
 * Two things were wrong and they pointed the same way — at a pipeline saying
 * something the dates underneath it did not support.
 *
 * A jersey is panelled and takes a longer press, so the model set aside a
 * fourth day for it. Nothing ever asked for that day: sampleLeadDays() handed
 * back the flat three whatever the product was, and the scheduler read the
 * constant directly rather than going through the method, so both halves
 * agreed on the wrong number. A jersey was late by design, which is the exact
 * thing the fourth day was written down to prevent.
 *
 * And the pipeline printed "due within 3 days" over every order, including
 * ones where no downpayment had been confirmed and so no schedule had been
 * drawn at all. A promise printed over dates that do not keep it is worse
 * than no promise: people stop reading the header.
 */
class TheSampleWindowSaysWhatItIsTest extends TestCase
{
    use RefreshDatabase;

    private int $made = 0;

    private function order(string $productType, array $extra = []): ProductionOrder
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $order = ProductionOrder::create(array_merge([
            'order_number' => 'IC2026-09'.str_pad((string) (++$this->made), 3, '0', STR_PAD_LEFT),
            'client_id' => Client::create(['name' => 'Sample', 'last_name' => 'Client'])->id,
            'customer_name' => 'Sample Client',
            'product_type' => $productType,
            'quantity' => 30,
            'unit_price' => 500,
            'due_date' => now()->addDays(20),
            'status' => 'active',
            'created_by' => $sales->id,
        ], $extra));

        $order->items()->create(['size' => 'M', 'quantity' => 30]);
        $order->jobOrder()->create(['status' => 'draft', 'created_by' => $sales->id]);

        return $order->refresh();
    }

    /** The money landing is what starts every one of these clocks. */
    private function payConfirmed(ProductionOrder $order, string $when = '-1 day'): void
    {
        $order->payments()->create([
            'amount' => 7500,
            'method' => 'GCash',
            'reference' => '123456789',
            'kind' => 'downpayment',
            'paid_at' => now()->parse($when),
            'confirmed_at' => now()->parse($when),
        ]);

        $order->refresh();
    }

    public function test_a_jersey_gets_the_fourth_day_and_a_shirt_does_not(): void
    {
        $this->assertSame(3, $this->order('round_neck')->sampleLeadDays());
        $this->assertSame(4, $this->order('riding_jersey')->sampleLeadDays(),
            'the jersey allowance is written down but never asked for');
        $this->assertSame(4, $this->order('regular_riding_jersey')->sampleLeadDays());
    }

    public function test_the_jersey_sample_is_due_a_day_later_than_a_shirts(): void
    {
        $shirt = $this->order('round_neck');
        $jersey = $this->order('riding_jersey');

        $this->payConfirmed($shirt, '-1 day');
        $this->payConfirmed($jersey, '-1 day');

        $start = now()->subDay()->startOfDay();

        $this->assertSame(
            $start->copy()->addDays(3)->toDateString(),
            $shirt->computeSampleDueDate()->toDateString()
        );
        $this->assertSame(
            $start->copy()->addDays(4)->toDateString(),
            $jersey->computeSampleDueDate()->toDateString()
        );
    }

    /**
     * The scheduler read the constant rather than the method, so even with the
     * method fixed a jersey's steps would still have been squeezed into three.
     */
    public function test_the_schedule_gives_the_jersey_sample_its_four_days(): void
    {
        $jersey = $this->order('riding_jersey');
        $this->payConfirmed($jersey, 'today');
        $jersey->buildPipeline([], 'manual');
        $jersey->refresh()->scheduleStepDeadlines();

        $sampleSteps = $jersey->tasks()->where('stage', '<', 10)->orderBy('sequence')->get();
        $this->assertNotEmpty($sampleSteps, 'the jersey built no sample phase to schedule');

        $lastSampleStep = $sampleSteps->last();

        $this->assertSame(
            now()->startOfDay()->addDays(4)->toDateString(),
            $lastSampleStep->due_at->toDateString(),
            'the sample phase still ended on the flat three-day constant'
        );
    }

    /** An unpaid order has no sample clock running, so it is not late. */
    public function test_an_unpaid_order_has_no_sample_date_and_is_not_overdue(): void
    {
        $order = $this->order('riding_jersey');

        $this->assertNull($order->computeSampleDueDate());
        $this->assertFalse($order->sampleOverdue());
    }

    /**
     * The column is only rewritten when a payment is confirmed. An order whose
     * payment went away keeps the date it had, and was called late for a clock
     * that is not running.
     */
    public function test_a_left_over_date_does_not_make_an_unpaid_order_late(): void
    {
        $order = $this->order('round_neck');
        $order->forceFill(['sample_due_date' => now()->subWeek()])->save();

        $this->assertFalse($order->refresh()->sampleOverdue(),
            'an order with no confirmed payment was reported as a late sample');
    }

    public function test_the_pipeline_header_says_the_clock_has_not_started(): void
    {
        $order = $this->order('riding_jersey');
        $leader = User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);
        $order->buildPipeline([], 'manual');

        $this->actingAs($leader)->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('4 days, starting when the downpayment is confirmed')
            ->assertDontSee('due within 3 days');
    }

    public function test_once_paid_the_header_names_the_day_the_sample_is_due(): void
    {
        $order = $this->order('riding_jersey');
        $leader = User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);
        $order->buildPipeline([], 'manual');
        $this->payConfirmed($order, 'today');

        $due = now()->startOfDay()->addDays(4)->format('M j, Y');

        $this->actingAs($leader)->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('due '.$due)
            ->assertSee('4 days from the confirmed payment');
    }

    /** A shirt still reads three, so the fix did not simply move the number. */
    public function test_a_shirt_still_says_three(): void
    {
        $order = $this->order('round_neck');
        $leader = User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);
        $order->buildPipeline([], 'manual');

        $this->actingAs($leader)->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('3 days, starting when the downpayment is confirmed');
    }
}
