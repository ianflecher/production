<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Covers ProductionOrderController@updateStatus — hold / resume / cancel. */
class OrderStatusTest extends TestCase
{
    use RefreshDatabase;

    private function salesUser(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
    }

    private function makeOrder(User $user): ProductionOrder
    {
        $this->actingAs($user)->post('/orders', [
            'order_number' => 'IC2026-09700',
            'client_name' => 'Status Test Co',
            'due_date' => now()->addWeeks(2)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10],
        ]);

        return ProductionOrder::where('order_number', 'IC2026-09700')->firstOrFail();
    }

    public function test_leader_can_hold_resume_and_cancel_an_order(): void
    {
        $order = $this->makeOrder($this->salesUser());
        $leader = User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);

        $this->actingAs($leader)->post("/orders/{$order->id}/status", ['action' => 'hold']);
        $this->assertSame('on_hold', $order->refresh()->status);

        $this->actingAs($leader)->post("/orders/{$order->id}/status", ['action' => 'resume']);
        $this->assertSame('active', $order->refresh()->status);

        $this->actingAs($leader)->post("/orders/{$order->id}/status", ['action' => 'cancel']);
        $this->assertSame('cancelled', $order->refresh()->status);
    }

    public function test_cancel_also_cancels_incomplete_tasks(): void
    {
        $order = $this->makeOrder($this->salesUser());
        $leader = User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);

        $this->actingAs($leader)->post("/orders/{$order->id}/status", ['action' => 'cancel']);

        // No task should remain in a non-terminal state.
        $this->assertSame(0, $order->tasks()->whereNotIn('status', ['complete', 'cancelled'])->count());
    }

    public function test_sales_cannot_change_order_status(): void
    {
        $sales = $this->salesUser();
        $order = $this->makeOrder($sales);

        // Status changes are leader/super_admin only.
        $this->actingAs($sales)->post("/orders/{$order->id}/status", ['action' => 'hold'])->assertForbidden();
        $this->assertSame('active', $order->refresh()->status);
    }

    public function test_status_action_must_be_valid(): void
    {
        $order = $this->makeOrder($this->salesUser());
        $leader = User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);

        $this->actingAs($leader)->post("/orders/{$order->id}/status", ['action' => 'explode'])
            ->assertInvalid(['action']);
    }
}
