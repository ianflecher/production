<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A job that owes nothing is not waiting for a downpayment.
 *
 * A sponsored sample, or one discounted down to nothing, sat forever behind
 * "waiting for downpayment" — the gate asks whether confirmed money has
 * arrived, and on a zero-peso job none ever will. The job order could not be
 * sent and the layout step could not start.
 */
class NothingOwedNeedsNoDownpaymentTest extends TestCase
{
    use RefreshDatabase;

    private function order(?float $total, float $discount = 0): ProductionOrder
    {
        $user = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        return ProductionOrder::create([
            'order_number' => 'IC2026-0'.random_int(1000, 9999),
            'customer_name' => 'Juan Dela Cruz',
            'client_id' => Client::create([
                'name' => 'Juan', 'last_name' => 'Dela Cruz', 'contact_number' => '0917',
                'office_address' => 'Angeles City', 'delivery_address' => 'Angeles City',
                'created_by' => $user->id,
            ])->id,
            'product_type' => 'round_neck',
            'quantity' => 10,
            'unit_price' => $total === null ? null : 500,
            'total_price' => $total,
            'discount_amount' => $discount,
            'due_date' => now()->addWeeks(3)->toDateString(),
            'status' => 'active',
            'created_by' => $user->id,
        ]);
    }

    public function test_a_fully_sponsored_job_owes_nothing(): void
    {
        $order = $this->order(0, 5000);

        $this->assertTrue($order->owesNothing());
        $this->assertTrue($order->hasDownpayment(), 'there is nothing left to wait for');
    }

    public function test_a_job_that_costs_money_still_waits(): void
    {
        $order = $this->order(5000);

        $this->assertFalse($order->owesNothing());
        $this->assertFalse($order->hasDownpayment(), 'no money has arrived yet');
    }

    public function test_an_unpriced_order_is_not_treated_as_paid(): void
    {
        // "For quotation" — no price agreed yet. This is the case the guard
        // exists for: an unpriced job must not walk onto the floor unpaid.
        $order = $this->order(null);

        $this->assertFalse($order->owesNothing());
        $this->assertFalse($order->hasDownpayment());
    }

    public function test_a_confirmed_payment_still_counts_the_normal_way(): void
    {
        $order = $this->order(5000);
        $finance = User::factory()->create(['job_role' => 'finance', 'is_active' => true]);

        $order->payments()->create([
            'amount' => 2500,
            'kind' => 'downpayment',
            'recorded_by' => $finance->id,
            'confirmed_at' => now(),
            'confirmed_by' => $finance->id,
        ]);

        $this->assertTrue($order->fresh()->hasDownpayment());
    }

    public function test_money_the_shop_has_not_confirmed_is_still_not_enough(): void
    {
        $order = $this->order(5000);
        $officer = User::findOrFail($order->created_by);

        // Recorded by the officer, not yet confirmed by finance.
        $order->payments()->create([
            'amount' => 2500,
            'kind' => 'downpayment',
            'recorded_by' => $officer->id,
        ]);

        $this->assertFalse($order->fresh()->hasDownpayment(),
            'the shop draws on confirmed money, not on a claim');
    }

    /**
     * The label has to say which desk the job is sitting on.
     *
     * "Needs downpayment" on an order whose money is already recorded and with
     * Finance sent officers back to clients who had paid days ago.
     */
    public function test_an_order_with_money_at_finance_is_not_labelled_unpaid(): void
    {
        $order = $this->order(5000);
        $order->update(['layout_status' => null]);
        $officer = User::findOrFail($order->created_by);

        $order->payments()->create([
            'amount' => 2500,
            'kind' => 'downpayment',
            'recorded_by' => $officer->id,
        ]);

        $order = $order->fresh();

        $this->assertFalse($order->hasDownpayment(), 'the money is not confirmed yet');
        $this->assertTrue($order->hasPaymentAwaitingFinance(), 'but it is not the officer who is holding it up');
    }

    public function test_an_order_with_no_money_at_all_is_still_the_officers_to_chase(): void
    {
        $order = $this->order(5000);

        $this->assertFalse($order->hasDownpayment());
        $this->assertFalse($order->hasPaymentAwaitingFinance(),
            'nothing recorded, so nobody at Finance is looking at it');
    }

    public function test_a_sponsored_job_is_waiting_on_nobody(): void
    {
        $order = $this->order(0, 5000);

        $this->assertTrue($order->hasDownpayment());
        $this->assertFalse($order->hasPaymentAwaitingFinance());
    }

    public function test_the_dashboard_stops_chasing_a_sponsored_job(): void
    {
        $sponsored = $this->order(0, 5000);
        $chargeable = $this->order(5000);

        $needsDp = fn (ProductionOrder $o) => in_array($o->status, ['active', 'on_hold'])
            && ! $o->hasDownpayment();

        $this->assertFalse($needsDp($sponsored));
        $this->assertTrue($needsDp($chargeable));
    }
}
