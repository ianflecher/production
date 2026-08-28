<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the core money-path: an account officer creating a production order.
 * This is the flow ProductionOrderController@store drives — validation,
 * pricing, client + order + items + draft job-order creation.
 */
class OrderCreationTest extends TestCase
{
    use RefreshDatabase;

    private function sales(): User
    {
        return User::factory()->create([
            'job_role' => User::ROLE_SALES,
            'is_active' => true,
        ]);
    }

    /** A minimal valid order payload (round_neck is a real product tier). */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'order_number' => 'IC2026-09001',
            'client_name' => 'Acme Corp',
            'client_last_name' => 'Cruz',
            'client_contact' => '0917-000-0000',
            'client_office_address' => 'Angeles City',
            'client_delivery_address' => 'Angeles City',
            'due_date' => now()->addWeeks(2)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10, 'L' => 5], // 15 pcs, well under daily capacity
        ], $overrides);
    }

    public function test_sales_can_create_an_order_with_client_items_and_draft_job_order(): void
    {
        $user = $this->sales();

        $response = $this->actingAs($user)->post('/orders', $this->payload());

        $order = ProductionOrder::where('order_number', 'IC2026-09001')->first();
        $this->assertNotNull($order, 'order was not created');
        $response->assertRedirect(route('orders.show', $order));

        // Core fields
        $this->assertSame(15, $order->quantity);
        $this->assertSame('active', $order->status);
        $this->assertSame($user->id, $order->created_by);
        $this->assertNotNull($order->total_price);
        $this->assertGreaterThan(0, (float) $order->total_price);

        // Client was created and linked
        $this->assertDatabaseHas('clients', ['name' => 'Acme Corp']);
        $this->assertSame($order->client_id, Client::where('name', 'Acme Corp')->value('id'));

        // Size breakdown persisted (M + L)
        $this->assertSame(2, $order->items()->count());
        $this->assertSame(10, (int) $order->items()->where('size', 'M')->value('quantity'));

        // A draft job order is created so the client reference can be attached
        $this->assertNotNull($order->jobOrder);
        $this->assertSame('draft', $order->jobOrder->status);
    }

    public function test_order_creation_requires_core_fields(): void
    {
        $response = $this->actingAs($this->sales())->post('/orders', []);

        $response->assertInvalid(['order_number', 'due_date', 'product_type', 'sizes']);
        $this->assertSame(0, ProductionOrder::count());
    }

    public function test_order_number_must_be_unique(): void
    {
        $user = $this->sales();
        $this->actingAs($user)->post('/orders', $this->payload());

        // Second order re-using the same number is rejected.
        $this->actingAs($user)
            ->post('/orders', $this->payload(['client_name' => 'Other Co']))
            ->assertInvalid(['order_number']);

        $this->assertSame(1, ProductionOrder::count());
    }

    public function test_order_exceeding_daily_capacity_is_rejected(): void
    {
        /*
         * Capacity is what the shop can finish on ONE DATE, across every order
         * due that day. It used to be tested with a single order of 600, but
         * one order can no longer be that big — the per-order ceiling is 500
         * and refuses it first, on 'sizes' rather than 'due_date'.
         *
         * Two orders is the shape that actually reaches the rule now, and it
         * is also the shape the shop meets: a full day filled by one client,
         * then another asking for the same date.
         */
        $sales = $this->sales();

        $this->actingAs($sales)->post('/orders', $this->payload([
            'sizes' => ['M' => 400],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(1, ProductionOrder::count());

        // 400 already promised, 300 more wanted, 500 is the day's limit.
        $this->actingAs($sales)->post('/orders', $this->payload([
            'order_number' => 'IC2026-FULL2',
            'client_name' => 'Second Co',
            'sizes' => ['M' => 300],
        ]))->assertInvalid(['due_date']);

        $this->assertSame(1, ProductionOrder::count(), 'the day was overbooked');
    }

    public function test_one_order_cannot_be_bigger_than_the_ceiling(): void
    {
        // The other half of the same guard: 600 in one order is refused for
        // being too big for an order, before the date is even considered.
        $this->actingAs($this->sales())->post('/orders', $this->payload([
            'sizes' => ['M' => 600],
        ]))->assertInvalid(['sizes']);

        $this->assertSame(0, ProductionOrder::count());
    }

    public function test_agent_cannot_create_orders(): void
    {
        $agent = User::factory()->create([
            'job_role' => User::JOB_PRODUCTION,
            'is_active' => true,
        ]);

        $this->actingAs($agent)->post('/orders', $this->payload())->assertForbidden();
        $this->assertSame(0, ProductionOrder::count());
    }
}
