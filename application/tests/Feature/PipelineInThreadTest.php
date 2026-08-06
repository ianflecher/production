<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nearly every message on a job order is somebody asking how far it has got,
 * so the thread carries the answer: where the job is standing, what picks it
 * up next, and the whole step list a click away.
 */
class PipelineInThreadTest extends TestCase
{
    use RefreshDatabase;

    private function order(): ProductionOrder
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-07070',
            'customer_name' => 'Thread Co',
            'product_type' => 'round_neck',
            'quantity' => 10,
            'due_date' => now()->addWeeks(2),
            'status' => 'active',
            'created_by' => $sales->id,
        ]);

        $sewing = User::factory()->create(['job_role' => 'Sewing', 'name' => 'Jully', 'is_active' => true]);

        $order->tasks()->create(['sequence' => 1, 'stage' => 1, 'department' => 'Layout', 'status' => 'complete', 'approver_role' => 'leader']);
        $order->tasks()->create(['sequence' => 2, 'stage' => 7, 'department' => 'Sewing', 'status' => 'in_progress', 'approver_role' => 'leader', 'assigned_to' => $sewing->id]);
        $order->tasks()->create(['sequence' => 3, 'stage' => 8, 'department' => 'Quality control', 'status' => 'todo', 'approver_role' => 'leader']);

        return $order->fresh();
    }

    private function mover(): User
    {
        return User::factory()->create(['job_role' => 'Mover', 'name' => 'Mover', 'is_active' => true]);
    }

    public function test_the_thread_says_where_the_job_is(): void
    {
        $order = $this->order();

        $this->actingAs($this->mover())->get("/messages/{$order->id}")
            ->assertOk()
            ->assertSee('1 of 3 steps done', false)
            ->assertSee('Now at', false)
            ->assertSee('Sewing', false)
            ->assertSee('Quality control', false);
    }

    public function test_it_names_who_is_holding_it(): void
    {
        $order = $this->order();

        $this->actingAs($this->mover())->get("/messages/{$order->id}")
            ->assertOk()
            ->assertSee('Jully', false);
    }

    public function test_the_whole_step_list_is_there(): void
    {
        $order = $this->order();

        $response = $this->actingAs($this->mover())->get("/messages/{$order->id}")->assertOk();

        // Collapsed, so it informs without crowding the conversation.
        $response->assertSee('Every step', false);
        $this->assertSame(3, substr_count($response->getContent(), 'class="dept"'));
    }

    public function test_a_late_job_says_so_in_the_thread_too(): void
    {
        $order = $this->order();
        $order->update(['due_date' => now()->subDays(3)]);

        $this->actingAs($this->mover())->get("/messages/{$order->id}")
            ->assertOk()
            ->assertSee('PROJECT DELAYED', false);
    }

    public function test_a_finished_job_reads_as_finished(): void
    {
        $order = $this->order();
        $order->tasks()->update(['status' => 'complete']);
        $order->update(['status' => 'complete']);

        $this->actingAs($this->mover())->get("/messages/{$order->id}")
            ->assertOk()
            ->assertSee('3 of 3 steps done', false)
            ->assertSee('Finished', false);
    }

    public function test_the_mover_no_longer_has_a_job_orders_tab(): void
    {
        $order = $this->order();

        $page = $this->actingAs($this->mover())->get("/messages/{$order->id}")->assertOk();

        // The thread carries the pipeline now, so the tab was a second way to
        // the same thing.
        $page->assertDontSee('>Job Orders<', false);

        // Still reachable from the thread itself, and still permitted.
        $page->assertSee('Open job order', false);
        $this->actingAs($this->mover())->get("/orders/{$order->id}")->assertOk();
    }

    public function test_the_pipeline_does_not_cost_a_query_per_step(): void
    {
        $order = $this->order();

        $queries = 0;
        \DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->actingAs($this->mover())->get("/messages/{$order->id}")->assertOk();

        // Tasks and their people are loaded with the order, not step by step.
        $this->assertLessThan(20, $queries, "the thread cost $queries queries");
    }
}
