<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The leader/sales approval workflow — how work actually moves through the
 * floor: approve, send back for revision, assign, unlock, force-complete.
 */
class ApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $jobRole): User
    {
        return User::factory()->create(['job_role' => $jobRole, 'is_active' => true]);
    }

    private function order(?User $sales = null): ProductionOrder
    {
        $sales ??= $this->user(User::ROLE_SALES);

        $this->actingAs($sales)->post('/orders', [
            'order_number' => 'IC2026-06060',
            'client_name' => 'Approve Co',
            'client_last_name' => 'Cruz',
            'client_contact' => '0917-000-0000',
            'client_office_address' => 'Angeles City',
            'client_delivery_address' => 'Angeles City',
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10],
        ]);

        return ProductionOrder::where('order_number', 'IC2026-06060')->firstOrFail();
    }

    /** A task sitting in FOR CHECKING, which is the only approvable state. */
    private function taskAwaitingCheck(ProductionOrder $order, string $approverRole = 'leader'): Task
    {
        $task = $order->tasks()->first();
        $task->update(['status' => 'for_checking', 'approver_role' => $approverRole]);

        return $task->fresh();
    }

    // ---- Approve -----------------------------------------------------------

    public function test_leader_can_approve_a_task_awaiting_check(): void
    {
        $order = $this->order();
        $task = $this->taskAwaitingCheck($order);

        $this->actingAs($this->user(User::ROLE_LEADER))
            ->post("/tasks/{$task->id}/approve")
            ->assertRedirect();

        $this->assertSame('complete', $task->fresh()->status);
    }

    public function test_a_task_not_awaiting_check_cannot_be_approved(): void
    {
        $order = $this->order();
        $task = $order->tasks()->first();
        $task->update(['status' => 'todo', 'approver_role' => 'leader']);

        $this->actingAs($this->user(User::ROLE_LEADER))
            ->post("/tasks/{$task->id}/approve")
            ->assertInvalid(['task']);

        $this->assertSame('todo', $task->fresh()->status);
    }

    public function test_an_agent_cannot_approve_work(): void
    {
        $order = $this->order();
        $task = $this->taskAwaitingCheck($order);

        $this->actingAs($this->user('sewing'))
            ->post("/tasks/{$task->id}/approve")
            ->assertForbidden();

        $this->assertSame('for_checking', $task->fresh()->status);
    }

    public function test_sales_cannot_approve_a_leader_owned_task(): void
    {
        $order = $this->order();
        $task = $this->taskAwaitingCheck($order, 'leader');

        $this->actingAs($this->user(User::ROLE_SALES))
            ->post("/tasks/{$task->id}/approve")
            ->assertForbidden();
    }

    public function test_sales_cannot_approve_a_sample_on_someone_elses_order(): void
    {
        $owner = $this->user(User::ROLE_SALES);
        $order = $this->order($owner);
        $task = $this->taskAwaitingCheck($order, 'sales');

        // A different account officer must not touch this order's sample.
        $this->actingAs($this->user(User::ROLE_SALES))
            ->post("/tasks/{$task->id}/approve")
            ->assertForbidden();

        $this->assertSame('for_checking', $task->fresh()->status);
    }

    public function test_sales_can_approve_a_sample_on_their_own_order(): void
    {
        $owner = $this->user(User::ROLE_SALES);
        $order = $this->order($owner);
        $task = $this->taskAwaitingCheck($order, 'sales');

        $this->actingAs($owner)->post("/tasks/{$task->id}/approve")->assertRedirect();

        $this->assertSame('complete', $task->fresh()->status);
    }

    // ---- Revision ----------------------------------------------------------

    public function test_leader_can_send_work_back_for_revision(): void
    {
        $order = $this->order();
        $task = $this->taskAwaitingCheck($order);

        $this->actingAs($this->user(User::ROLE_LEADER))
            ->post("/tasks/{$task->id}/revision", ['revision_note' => 'Logo is too small.'])
            ->assertRedirect();

        $fresh = $task->fresh();
        $this->assertSame(1, (int) $fresh->revision_count, 'revision should be counted');
        $this->assertNotSame('complete', $fresh->status);
    }

    public function test_revision_requires_a_note(): void
    {
        $order = $this->order();
        $task = $this->taskAwaitingCheck($order);

        $this->actingAs($this->user(User::ROLE_LEADER))
            ->post("/tasks/{$task->id}/revision", [])
            ->assertInvalid(['revision_note']);

        $this->assertSame(0, (int) $task->fresh()->revision_count);
    }

    public function test_agent_cannot_request_a_revision(): void
    {
        $order = $this->order();
        $task = $this->taskAwaitingCheck($order);

        $this->actingAs($this->user('sewing'))
            ->post("/tasks/{$task->id}/revision", ['revision_note' => 'nope'])
            ->assertForbidden();
    }

    // ---- Assign ------------------------------------------------------------

    public function test_leader_can_assign_a_task_to_an_agent(): void
    {
        $order = $this->order();
        $task = $order->tasks()->first();
        $agent = $this->user('sewing');

        $this->actingAs($this->user(User::ROLE_LEADER))
            ->post("/tasks/{$task->id}/assign", ['assigned_to' => $agent->id])
            ->assertRedirect();

        $this->assertSame($agent->id, (int) $task->fresh()->assigned_to);
    }

    public function test_an_agent_with_an_open_task_is_not_given_a_second_one(): void
    {
        $order = $this->order();
        $tasks = $order->tasks()->take(2)->get();
        $this->assertCount(2, $tasks, 'need two tasks for this test');

        $agent = $this->user('sewing');
        $leader = $this->user(User::ROLE_LEADER);

        // First assignment sticks.
        $this->actingAs($leader)->post("/tasks/{$tasks[0]->id}/assign", ['assigned_to' => $agent->id]);
        $this->assertSame($agent->id, (int) $tasks[0]->fresh()->assigned_to);

        // Second is refused — one job at a time.
        $this->actingAs($leader)
            ->post("/tasks/{$tasks[1]->id}/assign", ['assigned_to' => $agent->id])
            ->assertInvalid(['assigned_to']);

        $this->assertNull($tasks[1]->fresh()->assigned_to);
    }

    public function test_sales_cannot_assign_tasks(): void
    {
        $order = $this->order();
        $task = $order->tasks()->first();
        $agent = $this->user('sewing');

        $this->actingAs($this->user(User::ROLE_SALES))
            ->post("/tasks/{$task->id}/assign", ['assigned_to' => $agent->id])
            ->assertForbidden();
    }

    // ---- Leader overrides --------------------------------------------------

    public function test_leader_can_force_complete_a_task(): void
    {
        $order = $this->order();
        $task = $order->tasks()->first();

        $this->actingAs($this->user(User::ROLE_LEADER))
            ->post("/tasks/{$task->id}/complete")
            ->assertRedirect();

        $this->assertSame('complete', $task->fresh()->status);
    }

    public function test_agent_cannot_force_complete_a_task(): void
    {
        $order = $this->order();
        $task = $order->tasks()->first();
        $before = $task->status;

        $this->actingAs($this->user('sewing'))
            ->post("/tasks/{$task->id}/complete")
            ->assertForbidden();

        $this->assertSame($before, $task->fresh()->status);
    }

    public function test_agent_cannot_unlock_a_task(): void
    {
        $order = $this->order();
        $task = $order->tasks()->first();

        $this->actingAs($this->user('sewing'))
            ->post("/tasks/{$task->id}/unlock")
            ->assertForbidden();
    }
}
