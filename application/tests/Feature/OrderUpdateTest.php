<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Covers ProductionOrderController@update — editing an order re-prices it. */
class OrderUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function salesUser(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
    }

    private function makeOrder(User $user): ProductionOrder
    {
        $this->actingAs($user)->post('/orders', [
            'order_number' => 'IC2026-09600',
            'client_name' => 'Edit Test Co',
            'client_last_name' => 'Cruz',
            'client_contact' => '0917-000-0000',
            'client_address' => 'Angeles City',
            'due_date' => now()->addWeeks(2)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10, 'L' => 5], // 15 pcs
        ]);

        return ProductionOrder::where('order_number', 'IC2026-09600')->firstOrFail();
    }

    public function test_editing_an_order_recalculates_quantity_and_price(): void
    {
        $user = $this->salesUser();
        $order = $this->makeOrder($user);
        $originalTotal = (float) $order->total_price;

        $this->actingAs($user)->post("/orders/{$order->id}", [
            'client_name' => 'Edit Test Co',
            'client_last_name' => 'Cruz',
            'client_contact' => '0917-000-0000',
            'client_address' => 'Angeles City',
            'due_date' => now()->addWeeks(2)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 20, 'L' => 20], // now 40 pcs
        ])->assertRedirect();

        $order->refresh();
        $this->assertSame(40, $order->quantity);
        $this->assertNotSame($originalTotal, (float) $order->total_price, 'total should change when quantity changes');
        $this->assertSame(2, $order->items()->count());
        $this->assertSame(20, (int) $order->items()->where('size', 'M')->value('quantity'));
    }

    public function test_cancelled_order_cannot_be_edited(): void
    {
        $user = $this->salesUser();
        $order = $this->makeOrder($user);
        $order->update(['status' => 'cancelled']);

        $this->actingAs($user)->post("/orders/{$order->id}", [
            'client_name' => 'Hacked',
            'client_last_name' => 'Cruz',
            'client_contact' => '0917-000-0000',
            'client_address' => 'Angeles City',
            'due_date' => now()->addWeeks(2)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 5],
        ])->assertForbidden();
    }

    public function test_sales_cannot_edit_another_officers_order(): void
    {
        $owner = $this->salesUser();
        $order = $this->makeOrder($owner);

        $other = $this->salesUser();
        $this->actingAs($other)->get("/orders/{$order->id}/edit")->assertForbidden();
    }
}
