<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Back" has to land somewhere the person can actually use.
 *
 * It used to be a two-way choice — sales and leaders to the order, everyone
 * else to My Tasks. The mover is neither: she has no task list, and no link to
 * one in her sidebar, so Back dropped her on an empty page.
 */
class BackLinkTest extends TestCase
{
    use RefreshDatabase;

    private function order(): ProductionOrder
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-06060',
            'customer_name' => 'Back Co',
            'product_type' => 'round_neck',
            'quantity' => 10,
            'due_date' => now()->addWeeks(2),
            'status' => 'active',
            'created_by' => $sales->id,
        ]);

        $order->jobOrder()->create(['status' => 'draft', 'created_by' => $sales->id]);

        // On the floor, which is what makes it the mover's.
        $order->tasks()->create([
            'sequence' => 1, 'stage' => 3, 'department' => 'Printer',
            'status' => 'in_progress', 'approver_role' => 'leader',
            'released_at' => now()->subHour(),
        ]);

        return $order->fresh();
    }

    private function mover(): User
    {
        return User::factory()->create(['job_role' => 'Mover', 'is_active' => true]);
    }

    public function test_the_mover_goes_back_to_the_conversation(): void
    {
        $order = $this->order();

        $this->actingAs($this->mover())->get("/orders/{$order->id}/job-order")
            ->assertOk()
            ->assertSee(route('messages.show', $order), false)
            ->assertDontSee(route('tasks.mine'), false);
    }

    public function test_she_is_never_sent_to_a_task_list_she_has_not_got(): void
    {
        $order = $this->order();
        $mover = $this->mover();

        // Her sidebar has no My Tasks, so landing there is a dead end.
        foreach (["/orders/{$order->id}/job-order", "/orders/{$order->id}/reference"] as $url) {
            $this->actingAs($mover)->get($url)
                ->assertOk()
                ->assertDontSee('Back to my tasks', false);
        }
    }

    public function test_sales_still_goes_back_to_the_order(): void
    {
        $order = $this->order();
        $sales = User::find($order->created_by);

        $this->actingAs($sales)->get("/orders/{$order->id}/job-order")
            ->assertOk()
            ->assertSee(route('orders.show', $order), false);
    }

    public function test_an_artist_still_goes_back_to_their_tasks(): void
    {
        $order = $this->order();
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $task = $order->tasks()->create([
            'sequence' => 2, 'stage' => 2, 'department' => 'Tech pack',
            'status' => 'in_progress', 'approver_role' => 'sales', 'assigned_to' => $artist->id,
        ]);

        // The sheet only opens to an artist once the officer has sent it.
        $order->jobOrder->update(['status' => 'sent_to_artist']);

        // Artists reach it through their own task, not the order route.
        $this->actingAs($artist)->get("/my-tasks/{$task->id}/job-order")
            ->assertOk()
            ->assertSee(route('tasks.mine'), false);
    }
}
