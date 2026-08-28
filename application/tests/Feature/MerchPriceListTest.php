<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\User;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Not everybody sells from the same price list.
 *
 * The merch officer quotes flat prices off a list of products the standard
 * tables have never carried, and a job keeps the prices it was quoted at.
 */
class MerchPriceListTest extends TestCase
{
    use RefreshDatabase;

    private function officer(?string $list): User
    {
        return User::factory()->create([
            'job_role' => User::ROLE_SALES,
            'price_list' => $list,
            'is_active' => true,
        ]);
    }

    public function test_the_merch_list_is_flat_whatever_the_quantity(): void
    {
        // A hybrid jersey is 1,450 for five and 1,450 for eighty. The standard
        // list would have dropped the price four times over that spread.
        foreach ([5, 24, 80] as $qty) {
            $quote = PricingService::quote('hybrid_riding_jersey_type_1', $qty, false, null, 'merch');

            $this->assertSame(1450.0, $quote['unit'], "wrong at $qty pcs");
            $this->assertSame(1450.0 * $qty, $quote['total']);
            $this->assertFalse($quote['needs_quote']);
        }
    }

    public function test_the_spread_is_the_decoration_not_a_range(): void
    {
        $this->assertSame(800.0, PricingService::quote('cotton_shirt_silkscreen', 10, false, null, 'merch')['unit']);
        $this->assertSame(850.0, PricingService::quote('cotton_shirt_embroidered', 10, false, null, 'merch')['unit']);

        $this->assertSame(950.0, PricingService::quote('polo_shirt', 10, false, null, 'merch')['unit']);
        $this->assertSame(1150.0, PricingService::quote('polo_shirt_embroidered', 10, false, null, 'merch')['unit']);
    }

    public function test_the_standard_list_still_falls_with_quantity(): void
    {
        $this->assertSame(750.0, PricingService::quote('round_neck', 10)['unit']);
        $this->assertSame(550.0, PricingService::quote('round_neck', 60)['unit']);
    }

    public function test_the_two_lists_do_not_see_each_others_products(): void
    {
        $this->assertArrayNotHasKey('round_neck', PricingService::products('merch'));
        $this->assertArrayNotHasKey('tubemask', PricingService::products('standard'));

        // A merch product priced off the standard list is nothing at all,
        // rather than quietly borrowing a shirt's price.
        $this->assertTrue(PricingService::quote('tubemask', 10, false, null, 'standard')['needs_quote']);
    }

    public function test_the_officers_list_decides_which_products_they_are_offered(): void
    {
        $merch = $this->officer('merch');
        $this->actingAs($merch)->get(route('orders.create', ['inquiry' => $this->inquiryFor($merch)->id]))
            ->assertOk()
            ->assertSee('Hybrid Riding Jersey', false)
            ->assertDontSee('Round Neck / V-Neck Shirt', false);

        $standard = $this->officer(null);
        $this->actingAs($standard)->get(route('orders.create', ['inquiry' => $this->inquiryFor($standard)->id]))
            ->assertOk()
            ->assertSee('Round Neck / V-Neck Shirt', false)
            ->assertDontSee('Hybrid Riding Jersey', false);
    }

    /** The order form is step two, reached from the inquiry holding the client. */
    private function inquiryFor(User $officer): \App\Models\Inquiry
    {
        $client = \App\Models\Client::create(['name' => 'Merch', 'last_name' => 'Buyer']);

        return \App\Models\Inquiry::create([
            'client_id' => $client->id,
            'created_by' => $officer->id,
            'status' => \App\Models\Inquiry::STATUS_OPEN,
        ]);
    }

    public function test_a_merch_product_cannot_be_ordered_off_the_standard_list(): void
    {
        $this->actingAs($this->officer(null))
            ->post(route('orders.store'), $this->payload(['product_type' => 'tubemask']))
            ->assertSessionHasErrors('product_type');
    }

    public function test_the_job_keeps_the_list_it_was_quoted_from(): void
    {
        $officer = $this->officer('merch');

        $this->actingAs($officer)
            ->post(route('orders.store'), $this->payload(['product_type' => 'tanktop']))
            ->assertSessionHasNoErrors();

        $order = ProductionOrder::firstOrFail();

        $this->assertSame('merch', $order->price_list);
        $this->assertSame(600.0, (float) $order->unit_price);
        $this->assertSame('Tanktop', $order->productLabel());

        // Moving the officer to another list does not re-price work already
        // quoted — a quotation that changes after the client has it is not a
        // quotation.
        $officer->update(['price_list' => null]);

        $this->assertSame('Tanktop', $order->fresh()->productLabel());
        $this->assertSame(600.0, (float) $order->fresh()->unit_price);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'order_number' => 'IC2026-M001',
            'client_name' => 'Merch',
            'client_last_name' => 'Buyer',
            'client_contact' => '09170000000',
            'client_address' => 'Cebu City',
            'product_type' => 'tanktop',
            'quantity' => 10,
            'sizes' => ['M' => 10],
            'due_date' => now()->addWeeks(3)->toDateString(),
        ], $overrides);
    }
}
