<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CS and a typed "other size" are not on the price list. The rest of the order
 * still is — so the charted pieces keep the automatic tier price and only the
 * off-chart ones are priced by hand.
 */
class OffChartSizePricingTest extends TestCase
{
    use RefreshDatabase;

    private function order(array $sizes, ?float $unit, ?float $customSize): ProductionOrder
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-'.fake()->unique()->numerify('#####'),
            'customer_name' => 'Off Chart Co',
            'product_type' => 'round_neck',
            'quantity' => array_sum($sizes),
            'due_date' => now()->addWeeks(3),
            'unit_price' => $unit,
            'custom_size_price' => $customSize,
            'created_by' => $sales->id,
            'status' => 'active',
        ]);

        foreach ($sizes as $size => $qty) {
            $order->items()->create(['size' => $size, 'quantity' => $qty]);
        }

        return $order->refresh();
    }

    public function test_the_charted_pieces_keep_the_tier_price(): void
    {
        // 20 M at 700, 5 CS at 900.
        $order = $this->order(['M' => 20, 'CS' => 5], 700, 900);
        $pb = $order->pricingBreakdown();

        $this->assertSame(20, $pb['charted_qty']);
        $this->assertSame(5, $pb['custom_size_qty']);
        $this->assertSame(4500.0, $pb['custom_size_amount']);
        $this->assertSame(18500.0, $pb['subtotal']);   // 14000 + 4500
        $this->assertSame(18500.0, $pb['total']);
    }

    public function test_a_typed_size_off_the_chart_is_priced_the_same_way(): void
    {
        $order = $this->order(['L' => 10, 'Kids 8' => 2], 700, 500);
        $pb = $order->pricingBreakdown();

        $this->assertSame(2, $pb['custom_size_qty']);
        $this->assertSame(8000.0, $pb['subtotal']);    // 7000 + 1000
    }

    public function test_without_a_custom_price_every_piece_stays_on_the_tier(): void
    {
        $order = $this->order(['M' => 20, 'CS' => 5], 700, null);
        $pb = $order->pricingBreakdown();

        $this->assertSame(0, $pb['custom_size_qty']);
        $this->assertSame(17500.0, $pb['subtotal']);   // 25 x 700
    }

    public function test_an_order_with_no_off_chart_pieces_is_unchanged(): void
    {
        $order = $this->order(['M' => 20, 'L' => 5], 700, null);

        $this->assertSame(17500.0, $order->pricingBreakdown()['subtotal']);
    }

    public function test_the_order_page_shows_the_off_chart_line(): void
    {
        $order = $this->order(['M' => 20, 'CS' => 5], 700, 900);
        $order->recomputeTotal();

        $this->actingAs(User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]))
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('Off-chart sizes (5 pcs)')
            ->assertSee('20 pcs on the price list');
    }
}
