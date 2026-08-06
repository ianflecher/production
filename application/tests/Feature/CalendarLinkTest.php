<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Where a job on the calendar opens.
 *
 * Everything pointed at the order page, which leads with payments, pricing and
 * the client's details. That is the account officer's business — anyone else
 * clicking a job on a calendar wants the job order sheet.
 */
class CalendarLinkTest extends TestCase
{
    use RefreshDatabase;

    private function order(): ProductionOrder
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-05050',
            'customer_name' => 'Calendar Co',
            'product_type' => 'round_neck',
            'quantity' => 25,
            'due_date' => now()->addDays(4),
            'status' => 'active',
            'created_by' => $sales->id,
        ]);

        $order->jobOrder()->create(['status' => 'draft', 'created_by' => $sales->id]);

        // On the floor — which is what lets the mover open it at all.
        $order->tasks()->create([
            'sequence' => 1, 'stage' => 3, 'department' => 'Printer',
            'status' => 'in_progress', 'approver_role' => 'leader',
            'released_at' => now()->subHour(),
        ]);

        return $order->fresh();
    }

    public function test_the_mover_lands_on_the_job_order_sheet(): void
    {
        $order = $this->order();
        $mover = User::factory()->create(['job_role' => 'Mover', 'is_active' => true]);

        $this->actingAs($mover)->get('/calendar')
            ->assertOk()
            ->assertSee(route('orders.job-order', $order), false)
            ->assertDontSee(route('orders.show', $order).'"', false);
    }

    public function test_sales_still_lands_on_the_order(): void
    {
        $order = $this->order();
        $sales = User::find($order->created_by);

        // Payments and pricing are the reason an officer opens a job at all.
        $this->actingAs($sales)->get('/calendar')
            ->assertOk()
            ->assertSee(route('orders.show', $order), false);
    }

    public function test_a_leader_still_lands_on_the_order(): void
    {
        $order = $this->order();
        $leader = User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);

        $this->actingAs($leader)->get('/calendar')
            ->assertOk()
            ->assertSee(route('orders.show', $order), false);
    }

    public function test_the_sheet_actually_opens_for_her(): void
    {
        $order = $this->order();
        $mover = User::factory()->create(['job_role' => 'Mover', 'is_active' => true]);

        // A link is no use if the page behind it refuses her.
        $this->actingAs($mover)->get(route('orders.job-order', $order))->assertOk();
    }
}
