<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Agents work from /my-tasks and must only ever see their own assignments. */
class TaskAccessTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrderWithTasks(): ProductionOrder
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $this->actingAs($sales)->post('/orders', [
            'order_number' => 'IC2026-07777',
            'client_name' => 'Task Co',
            'client_last_name' => 'Cruz',
            'client_contact' => '0917-000-0000',
            'client_address' => 'Angeles City',
            'due_date' => now()->addWeeks(2)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10],
        ]);

        return ProductionOrder::where('order_number', 'IC2026-07777')->firstOrFail();
    }

    public function test_agent_cannot_open_a_task_assigned_to_someone_else(): void
    {
        $order = $this->makeOrderWithTasks();
        $owner = User::factory()->create(['job_role' => 'sewing', 'is_active' => true]);
        $other = User::factory()->create(['job_role' => 'sewing', 'is_active' => true]);

        $task = $order->tasks()->first();
        $this->assertNotNull($task, 'order should have pipeline tasks');
        $task->update(['assigned_to' => $owner->id]);

        // Denied. The lookup is scoped to the signed-in user, so another agent
        // gets 404 rather than 403 — that hides even the existence of the task.
        $this->actingAs($other)->get("/my-tasks/{$task->id}")->assertNotFound();
    }

    public function test_agent_can_open_their_own_task(): void
    {
        $order = $this->makeOrderWithTasks();
        $owner = User::factory()->create(['job_role' => 'sewing', 'is_active' => true]);

        $task = $order->tasks()->first();
        $task->update(['assigned_to' => $owner->id, 'status' => 'todo']);

        $this->actingAs($owner)->get("/my-tasks/{$task->id}")->assertOk();
    }

    public function test_agent_cannot_start_someone_elses_task(): void
    {
        $order = $this->makeOrderWithTasks();
        $owner = User::factory()->create(['job_role' => 'sewing', 'is_active' => true]);
        $other = User::factory()->create(['job_role' => 'sewing', 'is_active' => true]);

        $task = $order->tasks()->first();
        $task->update(['assigned_to' => $owner->id, 'status' => 'todo']);

        // Scoped lookup -> 404 for a task that isn't theirs. The security
        // property that matters: it is not started.
        $this->actingAs($other)->post("/my-tasks/{$task->id}/start")->assertNotFound();
        $this->assertSame('todo', $task->fresh()->status, 'task must not have been started');
    }

    public function test_my_tasks_page_loads_for_an_agent(): void
    {
        $agent = User::factory()->create(['job_role' => 'sewing', 'is_active' => true]);

        $this->actingAs($agent)->get('/my-tasks')->assertOk();
    }

    public function test_agent_cannot_reach_leader_approvals(): void
    {
        $agent = User::factory()->create(['job_role' => 'sewing', 'is_active' => true]);

        $this->actingAs($agent)->get('/approvals')->assertForbidden();
    }
}
