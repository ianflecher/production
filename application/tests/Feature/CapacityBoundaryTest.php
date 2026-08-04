<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Proves the exact daily-capacity boundary: 450 + 51 rejected, 450 + 50 allowed. */
class CapacityBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function sales(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
    }

    private function payload(string $num, int $qty, string $date): array
    {
        return [
            'order_number' => $num,
            'client_name' => 'Cap Co',
            'client_last_name' => 'Cruz',
            'client_contact' => '0917-000-0000',
            'client_office_address' => 'Angeles City',
            'client_delivery_address' => 'Angeles City',
            'due_date' => $date,
            'product_type' => 'round_neck',
            'sizes' => ['M' => $qty],
        ];
    }

    public function test_450_then_51_is_blocked(): void
    {
        $user = $this->sales();
        $date = now()->addWeeks(3)->toDateString();

        // Book 450 pcs on the date.
        $this->actingAs($user)->post('/orders', $this->payload('IC2026-00450', 450, $date));
        $this->assertSame(450, (int) ProductionOrder::whereDate('due_date', $date)->sum('quantity'));

        // Try to add 51 more (would be 501) -> must be rejected, order NOT created.
        $this->actingAs($user)->post('/orders', $this->payload('IC2026-00051', 51, $date))
            ->assertInvalid(['due_date']);

        $this->assertSame(1, ProductionOrder::count(), '51 should have been rejected');
        $this->assertSame(450, (int) ProductionOrder::whereDate('due_date', $date)->sum('quantity'));
    }

    public function test_450_then_50_is_allowed(): void
    {
        $user = $this->sales();
        $date = now()->addWeeks(3)->toDateString();

        $this->actingAs($user)->post('/orders', $this->payload('IC2026-00450', 450, $date));

        // Exactly 50 more -> 500 total -> allowed (rule is <= 500).
        $this->actingAs($user)->post('/orders', $this->payload('IC2026-00050', 50, $date));

        $this->assertSame(2, ProductionOrder::count(), '50 should have been allowed');
        $this->assertSame(500, (int) ProductionOrder::whereDate('due_date', $date)->sum('quantity'));
    }
}
