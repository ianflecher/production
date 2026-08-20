<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The orders list is read from the top, so the late work belongs there.
 *
 * The badges were already drawn in red, but a delayed job sat wherever its
 * order number put it — which on a full page is below the fold. And finished
 * orders shared the list with live ones, so after a busy year most of what you
 * scrolled past was work that needed nothing.
 */
class OrdersListUrgencyTest extends TestCase
{
    use RefreshDatabase;

    private function leader(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);
    }

    private function order(string $number, ?string $due, string $status = 'active'): ProductionOrder
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        return ProductionOrder::create([
            'order_number' => $number, 'customer_name' => 'Queue Co',
            'product_type' => 'round_neck', 'quantity' => 10,
            'due_date' => $due, 'created_by' => $sales->id, 'status' => $status,
        ]);
    }

    /** The order numbers as the page lists them. */
    private function listed(User $who, string $url = '/orders'): array
    {
        return $this->actingAs($who)->get($url)->assertOk()
            ->viewData('orders')->pluck('order_number')->all();
    }

    public function test_late_work_is_listed_first(): void
    {
        // Deliberately numbered so plain id or number ordering gets it wrong.
        $this->order('IC2026-00001', now()->addMonth()->toDateString());
        $this->order('IC2026-00002', now()->addWeek()->toDateString());
        $this->order('IC2026-00003', now()->subDays(8)->toDateString());

        $this->assertSame('IC2026-00003', $this->listed($this->leader())[0]);
    }

    public function test_the_most_overdue_comes_before_the_less_overdue(): void
    {
        $this->order('IC2026-00001', now()->subDay()->toDateString());
        $this->order('IC2026-00002', now()->subDays(9)->toDateString());

        $this->assertSame(
            ['IC2026-00002', 'IC2026-00001'],
            $this->listed($this->leader())
        );
    }

    public function test_due_today_sits_under_the_late_work_and_above_the_rest(): void
    {
        $this->order('IC2026-00001', now()->addMonth()->toDateString());
        $this->order('IC2026-00002', now()->toDateString());
        $this->order('IC2026-00003', now()->subDays(3)->toDateString());

        $this->assertSame(
            ['IC2026-00003', 'IC2026-00002', 'IC2026-00001'],
            $this->listed($this->leader())
        );
    }

    public function test_an_order_with_no_due_date_does_not_jump_the_queue(): void
    {
        $this->order('IC2026-00001', null);
        $this->order('IC2026-00002', now()->subDays(2)->toDateString());

        $this->assertSame('IC2026-00002', $this->listed($this->leader())[0]);
    }

    public function test_a_late_date_on_a_finished_order_is_not_urgent(): void
    {
        // It is done. A past due date on it is history, not a problem.
        $this->order('IC2026-00001', now()->addWeek()->toDateString());
        $this->order('IC2026-00002', now()->subMonth()->toDateString(), 'complete');

        $this->assertSame(['IC2026-00001'], $this->listed($this->leader()));
    }

    public function test_finished_orders_are_not_in_the_default_list(): void
    {
        $this->order('IC2026-00001', now()->addWeek()->toDateString());
        $this->order('IC2026-00002', now()->addWeek()->toDateString(), 'complete');

        $listed = $this->listed($this->leader());

        $this->assertContains('IC2026-00001', $listed);
        $this->assertNotContains('IC2026-00002', $listed);
    }

    public function test_they_have_their_own_tab(): void
    {
        $this->order('IC2026-00001', now()->addWeek()->toDateString());
        $this->order('IC2026-00002', now()->addWeek()->toDateString(), 'complete');

        $this->assertSame(['IC2026-00002'], $this->listed($this->leader(), '/orders?status=complete'));
    }

    public function test_the_first_card_counts_what_the_list_actually_shows(): void
    {
        // Calling it "Total orders" while hiding the finished ones is a number
        // that does not match its own list.
        $this->order('IC2026-00001', now()->addWeek()->toDateString());
        $this->order('IC2026-00002', now()->addWeek()->toDateString(), 'complete');

        $this->actingAs($this->leader())->get('/orders')
            ->assertOk()
            ->assertSee('Open orders')
            ->assertDontSee('Total orders');
    }

    public function test_on_hold_work_is_still_listed(): void
    {
        // Only finished work is hidden — a held job is still open business.
        $this->order('IC2026-00001', now()->addWeek()->toDateString(), 'on_hold');

        $this->assertSame(['IC2026-00001'], $this->listed($this->leader()));
    }
}
