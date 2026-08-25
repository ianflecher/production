<?php

namespace Tests\Feature;

use App\Models\OrderDocument;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The quotation, with the VAT and without it.
 *
 * Two documents come off the same order. A client who needs an official
 * receipt gets the VAT one; everybody else gets the plain delivery receipt.
 * The difference is twelve per cent of the money, so it is worth saying in a
 * test what each one adds up to rather than trusting that it always has.
 *
 * The numbering is the trap here, and it reads backwards: the VAT document is
 * TYPE_PQ and numbers as PQV, while the plain one is TYPE_DR and numbers as
 * PQ. A PQ number is therefore the document WITHOUT the VAT.
 */
class QuotationWithAndWithoutVatTest extends TestCase
{
    use RefreshDatabase;

    /** Counted up so two orders in one test do not collide on the number. */
    private int $made = 0;

    /** An order priced so the VAT is a round, checkable number. */
    private function order(bool $vat, array $extra = []): ProductionOrder
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-000'.(42 + $this->made++), 'customer_name' => 'Quote Co',
            'product_type' => 'round_neck', 'quantity' => 100,
            'unit_price' => 250,                    // 100 × 250 = 25,000
            'vat_inclusive' => $vat,
            'due_date' => now()->addWeeks(2), 'created_by' => $sales->id, 'status' => 'active',
        ] + $extra);

        $order->jobOrder()->create(['status' => 'draft', 'created_by' => $sales->id]);

        return $order->refresh();
    }

    public function test_without_the_vat_the_total_is_the_work(): void
    {
        $pb = $this->order(false)->pricingBreakdown();

        $this->assertSame(25000.0, $pb['subtotal']);
        $this->assertSame(25000.0, $pb['vatable']);
        $this->assertSame(0.0, $pb['vat'], 'a client who did not ask for a receipt was charged VAT');
        $this->assertSame(25000.0, $pb['total']);
    }

    public function test_with_the_vat_it_is_twelve_per_cent_on_top(): void
    {
        $pb = $this->order(true)->pricingBreakdown();

        $this->assertSame(25000.0, $pb['vatable']);
        $this->assertSame(3000.0, $pb['vat']);
        $this->assertSame(28000.0, $pb['total']);
    }

    public function test_the_discount_comes_off_before_the_vat(): void
    {
        // Twelve per cent of what is actually being charged, not of the list
        // price. The other way round quietly overcharges every discounted job.
        $pb = $this->order(true, ['discount_amount' => 5000])->pricingBreakdown();

        $this->assertSame(5000.0, $pb['discount']);
        $this->assertSame(20000.0, $pb['vatable']);
        $this->assertSame(2400.0, $pb['vat'], 'the VAT was taken on the price before the discount');
        $this->assertSame(22400.0, $pb['total']);
    }

    public function test_a_discount_bigger_than_the_job_does_not_pay_the_client(): void
    {
        $pb = $this->order(true, ['discount_amount' => 999999])->pricingBreakdown();

        $this->assertSame(25000.0, $pb['discount'], 'the discount was allowed past the whole price');
        $this->assertSame(0.0, $pb['vatable']);
        $this->assertSame(0.0, $pb['vat']);
        $this->assertSame(0.0, $pb['total']);
    }

    public function test_a_vat_order_is_offered_the_receipt_and_a_plain_one_is_not(): void
    {
        $this->assertSame(OrderDocument::TYPE_PQ, OrderDocument::defaultTypeFor($this->order(true)));
        $this->assertSame(OrderDocument::TYPE_DR, OrderDocument::defaultTypeFor($this->order(false)));
    }

    public function test_the_number_says_which_document_it_is(): void
    {
        $order = $this->order(true);

        // PQV carries the VAT; PQ does not. Worth pinning: the names read the
        // wrong way round, and a number is what somebody quotes on the phone.
        $this->assertSame('PQV2026-00042', OrderDocument::numberFor($order, OrderDocument::TYPE_PQ));
        $this->assertSame('PQ2026-00042', OrderDocument::numberFor($order, OrderDocument::TYPE_DR));
    }

    public function test_the_document_adds_the_vat_only_on_the_vat_one(): void
    {
        $order = $this->order(true);

        $lines = [
            ['description' => 'Round neck tee', 'quantity' => 100, 'unit_price' => 250],
        ];

        $withVat = new OrderDocument(['type' => OrderDocument::TYPE_PQ, 'items' => $lines]);
        $without = new OrderDocument(['type' => OrderDocument::TYPE_DR, 'items' => $lines]);

        $this->assertTrue($withVat->isVat());
        $this->assertSame(3000.0, $withVat->totals()['vat']);
        $this->assertSame(28000.0, $withVat->totals()['net']);

        $this->assertFalse($without->isVat());
        $this->assertSame(0.0, $without->totals()['vat']);
        $this->assertSame(25000.0, $without->totals()['net']);

        // The garment count is the same document either way.
        $this->assertSame(100, $withVat->totals()['quantity']);
        $this->assertSame(100, $without->totals()['quantity']);
    }

    public function test_an_added_line_is_money_but_not_a_garment(): void
    {
        // A back pocket is charged for and is not another shirt: it must not
        // inflate the piece count the client is quoted.
        $doc = new OrderDocument([
            'type' => OrderDocument::TYPE_PQ,
            'items' => [
                ['description' => 'Round neck tee', 'quantity' => 100, 'unit_price' => 250],
                ['description' => 'Back pocket', 'quantity' => 100, 'unit_price' => 10, 'addon' => true],
            ],
        ]);

        $this->assertSame(100, $doc->totals()['quantity'], 'the pockets were counted as garments');
        $this->assertSame(26000.0, $doc->totals()['amount']);
        $this->assertSame(3120.0, $doc->totals()['vat']);
        $this->assertSame(29120.0, $doc->totals()['net']);
    }

    public function test_the_sheet_says_which_one_the_reader_is_holding(): void
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $order = $this->order(true);
        $order->update(['created_by' => $sales->id]);

        $this->actingAs($sales)
            ->get(route('orders.document', [$order, OrderDocument::TYPE_PQ]))
            ->assertOk()
            ->assertSee('12% VAT');

        $this->actingAs($sales)
            ->get(route('orders.document', [$order, OrderDocument::TYPE_DR]))
            ->assertOk()
            ->assertSee('No VAT');
    }
}
