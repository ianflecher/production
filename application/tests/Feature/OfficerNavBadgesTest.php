<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The number beside Orders is the work still on the officer's books.
 *
 * Counted the way the Orders page opens — everything not finished — so the
 * badge and the list it points at cannot disagree. Cancelled jobs are nobody's
 * outstanding work, and somebody else's orders were never this officer's.
 */
class OfficerNavBadgesTest extends TestCase
{
    use RefreshDatabase;

    private function officer(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
    }

    private function orderFor(User $officer, string $number, string $status = 'active'): ProductionOrder
    {
        return ProductionOrder::create([
            'order_number' => $number,
            'client_id' => Client::create(['name' => 'A', 'last_name' => 'Client'])->id,
            'customer_name' => 'A Client',
            'product_type' => 'round_neck',
            'quantity' => 10,
            'due_date' => now()->addWeeks(2),
            'status' => $status,
            'created_by' => $officer->id,
        ]);
    }

    /** What the nav shows this officer beside Orders. */
    private function badge(User $officer): ?int
    {
        $html = $this->actingAs($officer)->get(route('orders.index'))->assertOk()->getContent();

        return preg_match('#Orders\s*<span class="count-pill">(\d+)</span>#s', $html, $m)
            ? (int) $m[1]
            : null;
    }

    public function test_it_counts_the_work_still_open(): void
    {
        $officer = $this->officer();

        $this->orderFor($officer, 'IC2026-N001');
        $this->orderFor($officer, 'IC2026-N002', 'on_hold');

        $this->assertSame(2, $this->badge($officer));
    }

    public function test_finished_and_cancelled_orders_are_not_outstanding(): void
    {
        $officer = $this->officer();

        $this->orderFor($officer, 'IC2026-N003', 'complete');
        $this->orderFor($officer, 'IC2026-N004', 'cancelled');

        $this->assertNull($this->badge($officer), 'nothing open, so no badge at all');
    }

    public function test_another_officers_orders_are_not_counted(): void
    {
        $officer = $this->officer();
        $this->orderFor($this->officer(), 'IC2026-N005');

        $this->assertNull($this->badge($officer));
    }

    public function test_the_badge_matches_the_officers_own_open_work(): void
    {
        $officer = $this->officer();

        $this->orderFor($officer, 'IC2026-N006');
        $this->orderFor($officer, 'IC2026-N007', 'on_hold');
        $this->orderFor($officer, 'IC2026-N008', 'complete');
        $this->orderFor($this->officer(), 'IC2026-N009');

        $expected = ProductionOrder::where('created_by', $officer->id)
            ->whereNotIn('status', ['complete', 'cancelled'])
            ->count();

        $this->assertSame($expected, $this->badge($officer));
    }
}
