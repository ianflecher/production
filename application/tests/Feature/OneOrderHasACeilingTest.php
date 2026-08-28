<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\User;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * There is a limit to what one order may ask for.
 *
 * The price tiers stop at a hundred, which says what a piece costs — not how
 * many the shop can make. An order for two thousand riding jerseys priced
 * itself happily and then sat on the floor for months.
 *
 * Five hundred of one product is already a long run; past that it is a
 * conversation rather than a form.
 */
class OneOrderHasACeilingTest extends TestCase
{
    use RefreshDatabase;

    private function officer(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
    }

    /** @return array<string, mixed> */
    private function orderForm(array $sizes): array
    {
        return [
            'client_name' => 'Big', 'client_last_name' => 'Order',
            'client_contact' => '0917 555 0000',
            'client_office_address' => 'Cebu City',
            'client_delivery_address' => 'Cebu City',
            'order_number' => 'IC2026-BIG1',
            'product_type' => 'riding_jersey',
            'due_date' => now()->addWeeks(6)->toDateString(),
            'sizes' => $sizes,
        ];
    }

    public function test_the_ceiling_is_five_hundred(): void
    {
        $this->assertSame(500, PricingService::maxQuantity('riding_jersey'));
        $this->assertSame(500, PricingService::maxQuantity('round_neck'));
    }

    public function test_an_order_within_it_is_taken(): void
    {
        $officer = $this->officer();

        $this->actingAs($officer)
            ->post(route('orders.store'), $this->orderForm(['M' => 300, 'L' => 200]))
            ->assertSessionHasNoErrors();

        $this->assertSame(500, (int) ProductionOrder::firstOrFail()->quantity);
    }

    public function test_one_piece_over_is_refused(): void
    {
        $officer = $this->officer();

        $this->actingAs($officer)
            ->post(route('orders.store'), $this->orderForm(['M' => 300, 'L' => 201]))
            ->assertSessionHasErrors('sizes');

        $this->assertSame(0, ProductionOrder::count(), 'an order over the ceiling was taken');
    }

    public function test_the_ceiling_is_on_the_order_not_on_one_size(): void
    {
        // Five hundred split across the size chart is still five hundred to
        // make: counting each size on its own would let any total through.
        $officer = $this->officer();

        $this->actingAs($officer)
            ->post(route('orders.store'), $this->orderForm([
                'S' => 150, 'M' => 150, 'L' => 150, 'XL' => 150,
            ]))
            ->assertSessionHasErrors('sizes');

        $this->assertSame(0, ProductionOrder::count());
    }

    public function test_the_refusal_says_what_to_do(): void
    {
        $officer = $this->officer();

        $this->actingAs($officer)
            ->post(route('orders.store'), $this->orderForm(['M' => 900]))
            ->assertSessionHasErrors('sizes');

        $message = session('errors')->first('sizes');

        $this->assertStringContainsString('900', $message);
        $this->assertStringContainsString('500', $message);
        $this->assertStringContainsString('split it', $message);
    }

    public function test_a_product_may_set_its_own(): void
    {
        // The ceiling belongs to the garment: a riding jersey is not a shirt.
        config(['pricing.lists.standard.products.round_neck.max_quantity' => 1200]);

        $this->assertSame(1200, PricingService::maxQuantity('round_neck'));
        $this->assertSame(500, PricingService::maxQuantity('riding_jersey'), 'the override leaked');
    }
}
