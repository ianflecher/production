<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\PaymentProof;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One receipt is not numbered.
 *
 * The number in front of a reference pairs it with the file button of the same
 * number beside it, which is worth saying when a payment came in on three
 * receipts. With one receipt there is nothing to pair it against, and the
 * finance page read "1." in front of every reference on it.
 */
class OneReceiptIsNotNumberedTest extends TestCase
{
    use RefreshDatabase;

    private function paymentWith(int $receipts): ProductionOrder
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-0'.random_int(1000, 9999),
            'customer_name' => 'Receipt Co',
            'product_type' => 'round_neck',
            'quantity' => 20,
            'due_date' => now()->addWeeks(2),
            'created_by' => $officer->id,
            'status' => 'active',
        ]);

        $payment = Payment::create([
            'production_order_id' => $order->id,
            'amount' => 5000,
            'method' => 'GCash',
            'kind' => 'downpayment',
            'recorded_by' => $officer->id,
        ]);

        foreach (range(1, $receipts) as $i) {
            PaymentProof::create([
                'payment_id' => $payment->id,
                'path' => 'payment-proofs/slip-'.$i.'.jpg',
                'original_name' => 'slip-'.$i.'.jpg',
                'reference' => '37190'.$i,
                'position' => $i,
            ]);
        }

        return $order->fresh();
    }

    private function financePage(): string
    {
        $finance = User::factory()->create(['job_role' => User::ROLE_FINANCE, 'is_active' => true]);

        return $this->actingAs($finance)->get(route('finance.index'))->assertOk()->getContent();
    }

    /** The case on screen: one receipt, no number in front of it. */
    public function test_a_single_receipt_reference_carries_no_number(): void
    {
        $this->paymentWith(1);

        $html = $this->financePage();

        $this->assertStringContainsString('371901', $html);
        $this->assertStringNotContainsString('>1. 371901<', $html);
    }

    /** Several still are, because that is what the number is for. */
    public function test_several_receipts_are_still_numbered(): void
    {
        $this->paymentWith(3);

        $html = $this->financePage();

        $this->assertStringContainsString('1. 371901', $html);
        $this->assertStringContainsString('2. 371902', $html);
        $this->assertStringContainsString('3. 371903', $html);
    }
}
