<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Three things the office asked for on the order form:
 *   - clients carry a surname, so the list sorts by family name
 *   - missing client details are named rather than silently accepted
 *   - an order can be marked RUSH with its own agreed fee
 */
class ClientAndRushTest extends TestCase
{
    use RefreshDatabase;

    private function sales(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
    }

    private function payload(array $o = []): array
    {
        return array_merge([
            'order_number' => 'IC2026-01111',
            'client_name' => 'Juan',
            'client_last_name' => 'Dela Cruz',
            'client_contact' => '0917-555-1234',
            'client_office_address' => '12 Rizal St., Angeles City',
            'client_delivery_address' => 'Same as office',
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10],
        ], $o);
    }

    // ---- Client surname ----------------------------------------------------

    public function test_the_client_keeps_a_surname_of_its_own(): void
    {
        $this->actingAs($this->sales())->post('/orders', $this->payload())->assertRedirect();

        $client = Client::firstOrFail();
        $this->assertSame('Juan', $client->name);
        $this->assertSame('Dela Cruz', $client->last_name);
        $this->assertSame('Juan Dela Cruz', $client->fullName());
        $this->assertSame('Dela Cruz, Juan', $client->listName());
    }

    public function test_the_order_records_the_clients_full_name(): void
    {
        $this->actingAs($this->sales())->post('/orders', $this->payload());

        $this->assertSame('Juan Dela Cruz', ProductionOrder::firstOrFail()->customer_name);
    }

    public function test_clients_sort_by_surname(): void
    {
        $user = $this->sales();
        $this->actingAs($user)->post('/orders', $this->payload([
            'order_number' => 'IC2026-01112', 'client_name' => 'Ana', 'client_last_name' => 'Zamora',
        ]));
        $this->actingAs($user)->post('/orders', $this->payload([
            'order_number' => 'IC2026-01113', 'client_name' => 'Zoe', 'client_last_name' => 'Abad',
        ]));

        // Sorted by family name, so Abad comes before Zamora even though the
        // first names run the other way.
        $this->assertSame(['Abad', 'Zamora'], Client::bySurname()->pluck('last_name')->all());
    }

    // ---- Missing details get named ----------------------------------------

    public function test_missing_client_details_are_each_reported(): void
    {
        $this->actingAs($this->sales())->post('/orders', [
            'order_number' => 'IC2026-01114',
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10],
        ])->assertInvalid([
            'client_name',
            'client_last_name',
            'client_contact',
            'client_office_address',
            'client_delivery_address',
        ]);

        $this->assertSame(0, ProductionOrder::count());
    }

    public function test_company_and_tin_stay_optional(): void
    {
        $this->actingAs($this->sales())->post('/orders', $this->payload())->assertRedirect();

        $this->assertSame(1, ProductionOrder::count(), 'an order should save without company or TIN');
    }

    public function test_an_existing_client_does_not_need_the_details_retyped(): void
    {
        $user = $this->sales();
        $this->actingAs($user)->post('/orders', $this->payload());
        $client = Client::firstOrFail();

        $this->actingAs($user)->post('/orders', [
            'order_number' => 'IC2026-01115',
            'client_id' => $client->id,
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 5],
        ])->assertRedirect();

        $this->assertSame(2, ProductionOrder::count());
    }

    // ---- Rush order --------------------------------------------------------

    public function test_a_rush_order_records_its_fee(): void
    {
        $this->actingAs($this->sales())->post('/orders', $this->payload([
            'rush' => 1,
            'rush_fee' => 1500,
        ]))->assertRedirect();

        $order = ProductionOrder::firstOrFail();
        $this->assertTrue((bool) $order->rush);
        $this->assertEqualsWithDelta(1500.0, (float) $order->rush_fee, 0.01);
    }

    public function test_the_rush_fee_is_added_to_the_total(): void
    {
        $user = $this->sales();

        $this->actingAs($user)->post('/orders', $this->payload(['order_number' => 'IC2026-01116']));
        $plain = (float) ProductionOrder::where('order_number', 'IC2026-01116')->value('total_price');

        $this->actingAs($user)->post('/orders', $this->payload([
            'order_number' => 'IC2026-01117', 'rush' => 1, 'rush_fee' => 1500,
        ]));
        $rushed = (float) ProductionOrder::where('order_number', 'IC2026-01117')->value('total_price');

        $this->assertEqualsWithDelta($plain + 1500, $rushed, 0.01);
    }

    public function test_ticking_rush_without_a_fee_is_refused(): void
    {
        $this->actingAs($this->sales())->post('/orders', $this->payload(['rush' => 1]))
            ->assertInvalid(['rush_fee']);

        $this->assertSame(0, ProductionOrder::count());
    }

    public function test_no_rush_means_no_fee_is_kept(): void
    {
        $this->actingAs($this->sales())->post('/orders', $this->payload(['rush_fee' => 999]));

        $order = ProductionOrder::firstOrFail();
        $this->assertFalse((bool) $order->rush);
        $this->assertNull($order->rush_fee, 'a fee must not stick without the tick');
    }

    public function test_the_rush_fee_shows_on_the_quotation(): void
    {
        $this->actingAs($this->sales())->post('/orders', $this->payload([
            'rush' => 1, 'rush_fee' => 1500,
        ]));
        $order = ProductionOrder::firstOrFail();

        $defaults = \App\Models\OrderDocument::defaultsFor($order, 'pq');
        $line = collect($defaults['items'])->firstWhere('description', 'Rush fee');

        $this->assertNotNull($line, 'the rush fee should be its own quotation line');
        $this->assertEqualsWithDelta(1500.0, (float) $line['unit_price'], 0.01);
    }

    // ---- Editing an order --------------------------------------------------

    /** @return array{0: User, 1: ProductionOrder} */
    private function existingOrder(): array
    {
        $user = $this->sales();
        $this->actingAs($user)->post('/orders', $this->payload());

        return [$user, ProductionOrder::firstOrFail()];
    }

    private function editPayload(array $o = []): array
    {
        return array_merge([
            'client_name' => 'Juan',
            'client_last_name' => 'Dela Cruz',
            'client_contact' => '0917-555-1234',
            'client_office_address' => '12 Rizal St., Angeles City',
            'client_delivery_address' => 'Same as office',
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10],
        ], $o);
    }

    public function test_an_edit_can_turn_an_order_into_a_rush_job(): void
    {
        [$user, $order] = $this->existingOrder();
        $before = (float) $order->total_price;

        $this->actingAs($user)
            ->post("/orders/{$order->id}", $this->editPayload(['rush' => 1, 'rush_fee' => 2000]))
            ->assertRedirect();

        $order->refresh();
        $this->assertTrue((bool) $order->rush);
        $this->assertEqualsWithDelta($before + 2000, (float) $order->total_price, 0.01);
    }

    public function test_an_edit_can_drop_the_rush_and_its_fee(): void
    {
        [$user, $order] = $this->existingOrder();
        $order->update(['rush' => true, 'rush_fee' => 2000]);

        $this->actingAs($user)->post("/orders/{$order->id}", $this->editPayload())->assertRedirect();

        $order->refresh();
        $this->assertFalse((bool) $order->rush);
        $this->assertNull($order->rush_fee);
    }

    public function test_an_edit_cannot_wipe_out_required_client_details(): void
    {
        [$user, $order] = $this->existingOrder();

        $this->actingAs($user)->post("/orders/{$order->id}", $this->editPayload([
            'client_last_name' => '',
            'client_contact' => '',
            'client_delivery_address' => '',
        ]))->assertInvalid(['client_last_name', 'client_contact', 'client_delivery_address']);
    }

    public function test_an_edit_keeps_the_surname_on_the_client(): void
    {
        [$user, $order] = $this->existingOrder();

        $this->actingAs($user)->post("/orders/{$order->id}", $this->editPayload([
            'client_last_name' => 'Santos',
        ]))->assertRedirect();

        $this->assertSame('Santos', $order->client->refresh()->last_name);
        $this->assertSame('Juan Santos', $order->refresh()->customer_name);
    }

    public function test_the_rush_fee_appears_in_the_price_breakdown(): void
    {
        $this->actingAs($this->sales())->post('/orders', $this->payload([
            'rush' => 1, 'rush_fee' => 1500,
        ]));

        $this->assertEqualsWithDelta(
            1500.0,
            ProductionOrder::firstOrFail()->pricingBreakdown()['rush'],
            0.01
        );
    }
}
