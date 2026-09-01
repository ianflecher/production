<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\OrderDocument;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The price quotation shows the same money as the order.
 *
 * The discount was recorded on the order and applied there, but the sheet knew
 * nothing about it: the client was shown the undiscounted price, VAT was
 * charged on it, and saving the sheet wrote that larger figure back over the
 * order's total — the discount undid itself.
 */
class QuotationDiscountTest extends TestCase
{
    use RefreshDatabase;

    private function order(float $discount, ?string $note = null, bool $vat = false): ProductionOrder
    {
        $user = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-09300',
            'customer_name' => 'Juan Dela Cruz',
            'client_id' => Client::create([
                'name' => 'Juan', 'last_name' => 'Dela Cruz', 'contact_number' => '0917',
                'office_address' => 'Angeles City', 'delivery_address' => 'Angeles City',
                'created_by' => $user->id,
            ])->id,
            'product_type' => 'round_neck',
            'quantity' => 10,
            'unit_price' => 500,
            'discount_amount' => $discount,
            'discount_note' => $note,
            'vat_inclusive' => $vat,
            'due_date' => now()->addWeeks(3)->toDateString(),
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $order->items()->create(['size' => 'M', 'quantity' => 10, 'description' => 'Round Neck']);

        return $order->refresh();
    }

    private function sheet(ProductionOrder $order, string $type): OrderDocument
    {
        $defaults = OrderDocument::defaultsFor($order, $type);

        return OrderDocument::create([
            'production_order_id' => $order->id,
            'type' => $type,
            'number' => $defaults['number'],
            'items' => $defaults['items'],
            'fields' => $defaults['fields'],
        ]);
    }

    public function test_the_discount_is_a_line_the_client_can_see(): void
    {
        $doc = $this->sheet($this->order(1000, 'team sponsorship'), OrderDocument::TYPE_DR);

        $line = collect($doc->items)->firstWhere('description', 'Discount — team sponsorship');

        $this->assertNotNull($line, 'the discount has its own line');
        $this->assertSame(-1000.0, (float) $line['unit_price'], 'it comes off, so it prints negative');
    }

    public function test_the_sheet_total_matches_the_order(): void
    {
        $order = $this->order(1000);
        $doc = $this->sheet($order, OrderDocument::TYPE_DR);

        // 10 x 500 = 5,000 less 1,000 = 4,000, on both.
        $this->assertSame(4000.0, (float) $order->pricingBreakdown()['total']);
        $this->assertSame(4000.0, (float) $doc->totals()['amount']);
        $this->assertSame(4000.0, (float) $doc->totals()['net']);
    }

    public function test_vat_is_charged_on_what_is_owed_not_on_the_full_price(): void
    {
        $order = $this->order(1000, null, true);
        $doc = $this->sheet($order, OrderDocument::TYPE_PQ);

        $totals = $doc->totals();

        // 12% of 4,000, not of 5,000 — the difference was 120 pesos of VAT
        // charged on money the client was never asked for.
        $this->assertSame(480.0, (float) $totals['vat']);
        $this->assertSame(4480.0, (float) $totals['net']);
        $this->assertSame((float) $order->pricingBreakdown()['vat'], (float) $totals['vat']);
        $this->assertSame((float) $order->pricingBreakdown()['total'], (float) $totals['net']);
    }

    public function test_no_discount_means_no_line_and_nothing_changes(): void
    {
        $order = $this->order(0);
        $doc = $this->sheet($order, OrderDocument::TYPE_DR);

        $this->assertNull(collect($doc->items)->firstWhere('addon', true));
        $this->assertSame(5000.0, (float) $doc->totals()['amount']);
        $this->assertSame((float) $order->pricingBreakdown()['total'], (float) $doc->totals()['net']);
    }

    public function test_a_discount_bigger_than_the_job_does_not_go_negative(): void
    {
        $order = $this->order(9999);
        $doc = $this->sheet($order, OrderDocument::TYPE_PQ);

        // pricingBreakdown caps it at the gross, so both come to nothing —
        // and the shop does not refund VAT on the overshoot.
        $this->assertSame(0.0, (float) $doc->totals()['amount']);
        $this->assertSame(0.0, (float) $doc->totals()['vat']);
        $this->assertSame(0.0, (float) $order->pricingBreakdown()['total']);
    }

    public function test_the_discount_line_does_not_count_as_garments(): void
    {
        $doc = $this->sheet($this->order(1000), OrderDocument::TYPE_DR);

        $this->assertSame(10, $doc->totals()['quantity'], 'ten shirts, not eleven items');
    }

    public function test_saving_the_sheet_keeps_the_discounted_total_on_the_order(): void
    {
        $order = $this->order(1000);
        $doc = $this->sheet($order, OrderDocument::TYPE_DR);

        // The officer whose order it is — writing the sheet lives in the
        // sales intake group, so a leader is refused at the route.
        $officer = User::findOrFail($order->created_by);

        $this->actingAs($officer)->post(route('orders.document.save', [$order, OrderDocument::TYPE_DR]), [
            'number' => $doc->number,
            'items' => collect($doc->items)->map(fn ($row) => [
                'description' => $row['description'],
                'size' => $row['size'] ?? '',
                'quantity' => $row['quantity'],
                'unit_price' => $row['unit_price'],
            ])->all(),
        ])->assertValid()->assertRedirect();

        // Saving used to write the undiscounted 5,000 back over the order.
        $this->assertSame(4000.0, (float) $order->refresh()->total_price);
    }
}
