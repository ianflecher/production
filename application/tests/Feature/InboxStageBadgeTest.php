<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Each conversation in the inbox says where its job stands — the step it is on,
 * or that it is finished. Most of these threads are somebody asking exactly
 * that, so the list answers it before anyone opens one.
 */
class InboxStageBadgeTest extends TestCase
{
    use RefreshDatabase;

    private function leader(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);
    }

    private function order(string $number = 'IC2026-03030'): ProductionOrder
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        return ProductionOrder::create([
            'order_number' => $number,
            'customer_name' => 'Badge Co',
            'product_type' => 'round_neck',
            'quantity' => 10,
            'due_date' => now()->addWeeks(2),
            'status' => 'active',
            'created_by' => $sales->id,
        ])->fresh();
    }

    private function step(ProductionOrder $order, string $dept, int $stage, string $status): void
    {
        $order->tasks()->create([
            'sequence' => ($order->tasks()->max('sequence') ?? 0) + 1,
            'stage' => $stage, 'department' => $dept,
            'status' => $status, 'approver_role' => 'leader',
            'released_at' => $status === 'todo' ? null : now()->subHour(),
        ]);
    }

    public function test_it_names_the_step_the_job_is_on(): void
    {
        $order = $this->order();
        $this->step($order, 'Printer', 3, 'complete');
        $this->step($order, 'Sewing', 7, 'in_progress');
        $this->step($order, 'Quality control', 8, 'todo');

        $this->actingAs($this->leader())->get('/messages')
            ->assertOk()
            ->assertSee('Sewing', false)
            ->assertSee('stage-tag is-live', false);
    }

    public function test_a_delivered_job_says_complete(): void
    {
        $order = $this->order();
        $this->step($order, 'Printer', 3, 'complete');
        $order->update(['status' => 'complete']);

        $this->actingAs($this->leader())->get('/messages')
            ->assertOk()
            ->assertSee('Complete', false)
            ->assertSee('stage-tag is-done', false);
    }

    public function test_a_job_with_nothing_released_says_not_started(): void
    {
        $order = $this->order();
        $this->step($order, 'Layout', 1, 'todo');

        $this->actingAs($this->leader())->get('/messages')
            ->assertOk()
            ->assertSee('Not started', false);
    }

    public function test_a_held_job_says_so(): void
    {
        $order = $this->order();
        $this->step($order, 'Sewing', 7, 'in_progress');
        $order->update(['status' => 'on_hold']);

        $this->actingAs($this->leader())->get('/messages')
            ->assertOk()
            ->assertSee('On hold', false);
    }

    public function test_a_cancelled_job_says_so(): void
    {
        $order = $this->order();
        $this->step($order, 'Sewing', 7, 'in_progress');
        $order->update(['status' => 'cancelled']);

        $this->actingAs($this->leader())->get('/messages')
            ->assertOk()
            ->assertSee('Cancelled', false);
    }

    public function test_the_mover_is_told_a_step_she_actually_follows(): void
    {
        $order = $this->order();
        $mover = User::factory()->create(['job_role' => 'Mover', 'is_active' => true]);

        // The artist's export is still open, but it is not her part of the line.
        $this->step($order, 'Export', 3, 'in_progress');
        $this->step($order, 'Printer', 3, 'in_progress');
        $this->step($order, 'Inventory', 15, 'todo');

        $this->actingAs($mover)->get('/messages')
            ->assertOk()
            ->assertSee('Printer', false)
            ->assertDontSee('>Export<', false);
    }
}
