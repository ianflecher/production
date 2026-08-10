<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nothing leaves the shop on an unpaid balance.
 *
 * "Release to client" is the last step before the goods are gone, so it is the
 * last chance to catch it — after that the only leverage left is asking nicely.
 */
class ReleaseRequiresFullPaymentTest extends TestCase
{
    use RefreshDatabase;

    /** An order sitting on its release step, with $paid recorded against it. */
    private function orderAtRelease(?float $total, float $paid = 0): array
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-0'.random_int(1000, 9999),
            'customer_name' => 'Release Co',
            'product_type' => 'round_neck',
            'quantity' => 10,
            'total_price' => $total,
            'due_date' => now()->addWeek(),
            'created_by' => $sales->id,
            // The review list only shows live orders.
            'status' => 'active',
        ]);

        if ($paid > 0) {
            Payment::create([
                'production_order_id' => $order->id,
                'amount' => $paid,
                'kind' => 'full',
                'recorded_by' => $sales->id,
            ]);
        }

        $task = Task::create([
            'production_order_id' => $order->id,
            'department' => 'Release to client',
            'sequence' => 16,
            'stage' => 3,
            'status' => 'for_checking',
            'approver_role' => 'sales',
            'assigned_to' => $sales->id,
            'submitted_at' => now(),
        ]);

        return [$sales, $order, $task];
    }

    public function test_release_is_refused_while_a_balance_is_outstanding(): void
    {
        [$sales, $order, $task] = $this->orderAtRelease(23800, paid: 12000);

        $this->actingAs($sales)
            ->post("/tasks/{$task->id}/approve")
            ->assertSessionHas('error');

        $this->assertNotSame('complete', $task->fresh()->status,
            'a part-paid order must not be releasable');
    }

    public function test_release_is_refused_when_no_price_has_been_set(): void
    {
        // Nothing to check the payments against — refuse rather than guess,
        // because guessing wrong hands over the goods for free.
        [$sales, $order, $task] = $this->orderAtRelease(null);

        $this->actingAs($sales)
            ->post("/tasks/{$task->id}/approve")
            ->assertSessionHas('error');

        $this->assertNotSame('complete', $task->fresh()->status);
    }

    public function test_release_goes_through_once_the_order_is_settled(): void
    {
        [$sales, $order, $task] = $this->orderAtRelease(23800, paid: 23800);

        $this->actingAs($sales)
            ->post("/tasks/{$task->id}/approve")
            ->assertSessionMissing('error');

        $this->assertSame('complete', $task->fresh()->status,
            'a fully paid order must release normally');
    }

    public function test_a_rounding_centavo_short_still_counts_as_paid(): void
    {
        // Splitting 100.00 three ways cannot sum back to exactly 100.00.
        [$sales, $order, $task] = $this->orderAtRelease(100, paid: 99.999);

        $this->assertTrue($order->fresh()->isFullyPaid());
    }

    public function test_the_review_page_says_why_it_cannot_be_released(): void
    {
        [$sales, $order, $task] = $this->orderAtRelease(23800, paid: 12000);

        $this->actingAs($sales)
            ->get('/sample-review')
            ->assertSee('Not paid in full')
            ->assertSee('11,800.00');
    }
}
