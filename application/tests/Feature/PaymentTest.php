<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Covers ProductionOrderController@recordPayment — the downpayment money-path. */
class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private function salesUser(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
    }

    private function makeOrder(User $user): ProductionOrder
    {
        $this->actingAs($user)->post('/orders', [
            'order_number' => 'IC2026-09800',
            'client_name' => 'Pay Test Co',
            'client_last_name' => 'Cruz',
            'client_contact' => '0917-000-0000',
            'client_address' => 'Angeles City',
            'due_date' => now()->addWeeks(2)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10],
        ]);

        return ProductionOrder::where('order_number', 'IC2026-09800')->firstOrFail();
    }

    private function approveLayout(ProductionOrder $order): void
    {
        $order->tasks()->where('stage', ProductionOrder::STAGE_LAYOUT)->update(['status' => 'complete']);
    }

    public function test_payment_is_blocked_until_the_layout_is_approved(): void
    {
        Storage::fake('local');
        $user = $this->salesUser();
        $order = $this->makeOrder($user);
        // NOTE: layout not approved yet.

        $this->actingAs($user)->post("/orders/{$order->id}/payment", [
            'portion' => 'half',
            'method' => 'GCash',
            'proof_references' => ['GC-1001'],
            'proofs' => [UploadedFile::fake()->image('proof.jpg')],
        ])->assertSessionHasErrors('payment');

        $this->assertSame(0, Payment::count());
    }

    public function test_downpayment_is_recorded_after_layout_approval(): void
    {
        Storage::fake('local');
        $user = $this->salesUser();
        $order = $this->makeOrder($user);
        $this->approveLayout($order);
        $this->assertTrue($order->fresh()->layoutApproved(), 'layout should be approved for this test');

        $expectedHalf = round((float) $order->total_price / 2, 2);

        $response = $this->actingAs($user)->post("/orders/{$order->id}/payment", [
            'portion' => 'half',
            'method' => 'GCash',
            'proof_references' => ['GC-1002'],
            'proofs' => [UploadedFile::fake()->image('proof.jpg')],
        ]);

        // Recording it is not receiving it. What the officer writes down is
        // what the client told them; the mockup waits for Finance to confirm
        // the money landed. See FinanceConfirmsBeforeWorkStartsTest.
        $response->assertRedirect(route('orders.show', $order));
        $this->assertTrue($order->fresh()->hasPaymentAwaitingFinance());
        $this->assertFalse(
            $order->fresh()->tasks()->where('department', 'Final mockup')->where('status', 'ready')->exists(),
            'the artist was set to work on a payment nobody had confirmed'
        );

        $this->assertDatabaseHas('payments', [
            'production_order_id' => $order->id,
            'kind' => 'downpayment',
            'method' => 'GCash',
        ]);
        $this->assertEqualsWithDelta($expectedHalf, (float) Payment::where('production_order_id', $order->id)->value('amount'), 0.01);
    }

    public function test_payment_requires_proof(): void
    {
        $user = $this->salesUser();
        $order = $this->makeOrder($user);
        $this->approveLayout($order);

        $this->actingAs($user)->post("/orders/{$order->id}/payment", [
            'portion' => 'half',
            'method' => 'GCash',
            'proof_references' => ['GC-1003'],
            // no proof file
        ])->assertInvalid(['proofs']);

        $this->assertSame(0, Payment::count());
    }

    public function test_payment_requires_a_reference_number(): void
    {
        Storage::fake('local');
        $user = $this->salesUser();
        $order = $this->makeOrder($user);
        $this->approveLayout($order);

        $this->actingAs($user)->post("/orders/{$order->id}/payment", [
            'portion' => 'half',
            'method' => 'GCash',
            'proofs' => [UploadedFile::fake()->image('proof.jpg')],
        ])->assertInvalid(['proof_references']);

        $this->assertSame(0, Payment::count());
    }

    public function test_an_agent_can_name_an_other_transfer_method(): void
    {
        Storage::fake('local');
        $user = $this->salesUser();
        $order = $this->makeOrder($user);
        $this->approveLayout($order);

        $this->actingAs($user)->post("/orders/{$order->id}/payment", [
            'portion' => 'half',
            'method' => Payment::METHOD_OTHER_TRANSFER,
            'other_transfer' => 'Maya',
            'proof_references' => ['MAYA-1001'],
            'proofs' => [UploadedFile::fake()->image('maya.jpg')],
        ])->assertRedirect();

        $this->assertSame('Other transfer — Maya', Payment::firstOrFail()->method);
    }

    public function test_other_transfer_requires_its_name(): void
    {
        Storage::fake('local');
        $user = $this->salesUser();
        $order = $this->makeOrder($user);
        $this->approveLayout($order);

        $this->actingAs($user)->post("/orders/{$order->id}/payment", [
            'portion' => 'half',
            'method' => Payment::METHOD_OTHER_TRANSFER,
            'proof_references' => ['OTHER-1001'],
            'proofs' => [UploadedFile::fake()->image('other.jpg')],
        ])->assertInvalid(['other_transfer']);
    }

    public function test_an_agent_can_record_a_custom_downpayment_of_more_than_half(): void
    {
        Storage::fake('local');
        $user = $this->salesUser();
        $order = $this->makeOrder($user);
        $this->approveLayout($order);
        $amount = round((float) $order->total_price * 0.75, 2);

        $this->actingAs($user)->post("/orders/{$order->id}/payment", [
            'portion' => 'custom_downpayment',
            'amount' => $amount,
            'method' => 'GCash',
            'proof_references' => ['GC-7500'],
            'proofs' => [UploadedFile::fake()->image('custom-downpayment.jpg')],
        ])->assertRedirect();

        $payment = Payment::firstOrFail();
        $this->assertSame('downpayment', $payment->kind);
        $this->assertEqualsWithDelta($amount, (float) $payment->amount, 0.01);
    }

    /**
     * A downpayment is whatever the client actually handed over.
     *
     * It had to be at least half - the shop's printed term, which the system
     * cannot enforce on a client standing at the counter with a third of it.
     * The officer was left recording nothing, so the job sat unstarted and the
     * money sat unrecorded: the opposite of what the rule was for.
     *
     * The quotation still asks for 50%. Agreeing the terms and writing down
     * what arrived are two different jobs.
     */
    public function test_a_first_downpayment_can_be_any_amount(): void
    {
        Storage::fake('local');
        $user = $this->salesUser();
        $order = $this->makeOrder($user);
        $this->approveLayout($order);
        $amount = round((float) $order->total_price * 0.30, 2);

        $this->actingAs($user)->post("/orders/{$order->id}/payment", [
            'portion' => 'custom_downpayment',
            'amount' => $amount,
            'method' => 'GCash',
            'proof_references' => ['GC-3000'],
            'proofs' => [UploadedFile::fake()->image('thirty-percent.jpg')],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $payment = Payment::firstOrFail();
        $this->assertSame('downpayment', $payment->kind);
        $this->assertEqualsWithDelta($amount, (float) $payment->amount, 0.01);
    }

    /** The ceiling stays: more than is owed is a mistake, not a payment. */
    public function test_a_downpayment_is_still_capped_at_what_is_owed(): void
    {
        Storage::fake('local');
        $user = $this->salesUser();
        $order = $this->makeOrder($user);
        $this->approveLayout($order);

        $this->actingAs($user)->post("/orders/{$order->id}/payment", [
            'portion' => 'custom_downpayment',
            'amount' => round((float) $order->total_price * 3, 2),
            'method' => 'GCash',
            'proof_references' => ['GC-9999'],
            'proofs' => [UploadedFile::fake()->image('too-much.jpg')],
        ])->assertRedirect();

        $this->assertEqualsWithDelta(
            (float) $order->total_price, (float) Payment::firstOrFail()->amount, 0.01,
            'the shop booked more money than the order is worth'
        );
    }
}
