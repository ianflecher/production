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
            'reference' => 'GC-1001',
            'proof' => UploadedFile::fake()->image('proof.jpg'),
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
            'reference' => 'GC-1002',
            'proof' => UploadedFile::fake()->image('proof.jpg'),
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
            'reference' => 'GC-1003',
            // no proof file
        ])->assertInvalid(['proof']);

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
            'proof' => UploadedFile::fake()->image('proof.jpg'),
        ])->assertInvalid(['reference']);

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
            'reference' => 'MAYA-1001',
            'proof' => UploadedFile::fake()->image('maya.jpg'),
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
            'reference' => 'OTHER-1001',
            'proof' => UploadedFile::fake()->image('other.jpg'),
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
            'reference' => 'GC-7500',
            'proof' => UploadedFile::fake()->image('custom-downpayment.jpg'),
        ])->assertRedirect();

        $payment = Payment::firstOrFail();
        $this->assertSame('downpayment', $payment->kind);
        $this->assertEqualsWithDelta($amount, (float) $payment->amount, 0.01);
    }

    public function test_a_first_custom_downpayment_cannot_be_less_than_half(): void
    {
        Storage::fake('local');
        $user = $this->salesUser();
        $order = $this->makeOrder($user);
        $this->approveLayout($order);

        $this->actingAs($user)->post("/orders/{$order->id}/payment", [
            'portion' => 'custom_downpayment',
            'amount' => round((float) $order->total_price * 0.49, 2),
            'method' => 'GCash',
            'reference' => 'GC-4900',
            'proof' => UploadedFile::fake()->image('too-small.jpg'),
        ])->assertSessionHasErrors('amount');

        $this->assertSame(0, Payment::count());
    }
}
