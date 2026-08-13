<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Handing the goods over belongs to whoever is holding them.
 *
 * "Release to client" is the last step of the pipeline — the boxes go across
 * the counter. It used to land on the account officer's Sample Review, asking
 * them to confirm a handover they were not part of and could not see, from a
 * page meant for looking at samples. The products desk is the one with the
 * stock in their hands.
 */
class ReleaseBelongsToProductsDeskTest extends TestCase
{
    use RefreshDatabase;

    /** An order finished and waiting to be handed over, with $paid recorded. */
    private function waitingToRelease(float $total = 30240, float $paid = 30240): array
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $desk = User::factory()->create(['job_role' => 'Inventory', 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-0'.random_int(1000, 9999),
            'customer_name' => 'Counter Co',
            'product_type' => 'round_neck',
            'quantity' => 42,
            'total_price' => $total,
            'due_date' => now()->addWeek(),
            'created_by' => $sales->id,
            'status' => 'active',
        ]);

        if ($paid > 0) {
            Payment::create([
                'production_order_id' => $order->id, 'amount' => $paid,
                'kind' => 'full', 'recorded_by' => $sales->id,
            ]);
        }

        $task = Task::create([
            'production_order_id' => $order->id,
            'department' => 'Release to client',
            'sequence' => 20, 'stage' => 16,
            'status' => 'for_checking',
            'approver_role' => 'inventory',
            'submitted_at' => now(),
        ]);

        return [$desk, $sales, $order, $task];
    }

    public function test_the_products_desk_is_shown_the_order_to_release(): void
    {
        [$desk, , $order] = $this->waitingToRelease();

        $this->actingAs($desk)->get('/products')
            ->assertOk()
            ->assertSee('To release')
            ->assertSee($order->order_number);
    }

    public function test_it_is_gone_from_the_account_officers_sample_review(): void
    {
        [, $sales, $order] = $this->waitingToRelease();

        $this->actingAs($sales)->get('/sample-review')
            ->assertOk()
            ->assertDontSee($order->order_number);
    }

    public function test_the_products_desk_can_close_the_order(): void
    {
        [$desk, , , $task] = $this->waitingToRelease();

        $this->actingAs($desk)->post("/products/release/{$task->id}", ['operator_name' => 'Rowena'])
            ->assertSessionMissing('error');

        $this->assertSame('complete', $task->fresh()->status);
    }

    public function test_the_payment_gate_came_with_it(): void
    {
        // The whole point of the gate is that it stands where the goods are
        // handed over — moving the step without it would open the door.
        [$desk, , , $task] = $this->waitingToRelease(30240, paid: 15120);

        $this->actingAs($desk)->post("/products/release/{$task->id}", ['operator_name' => 'Rowena'])
            ->assertSessionHas('error');

        $this->assertNotSame('complete', $task->fresh()->status);
    }

    public function test_an_unpaid_order_is_flagged_on_the_page_not_just_refused(): void
    {
        [$desk, , $order] = $this->waitingToRelease(30240, paid: 15120);

        $this->actingAs($desk)->get('/products')
            ->assertOk()
            ->assertSee($order->order_number)
            ->assertSee('UNPAID')
            ->assertSee('Cannot release')
            ->assertDontSee('Released to client')
            // Recording the money is the account officer's job. Offering it
            // here invites the wrong desk to mark a payment received to get a
            // client off their counter.
            ->assertDontSee('Record the payment');
    }

    public function test_the_account_officer_cannot_release_it_from_elsewhere(): void
    {
        [, $sales, , $task] = $this->waitingToRelease();

        // Not their step any more, even on their own order.
        $this->actingAs($sales)->post("/products/release/{$task->id}", ['operator_name' => 'Rowena'])->assertForbidden();
    }

    public function test_the_person_who_handed_it_over_is_recorded(): void
    {
        // The desk is a shared login, so the account says nothing. Without a
        // name the last line of the pipeline read "—" on the one movement
        // where somebody actually signed for the goods.
        [$desk, , , $task] = $this->waitingToRelease();

        $this->actingAs($desk)->post("/products/release/{$task->id}", ['operator_name' => 'Rowena']);

        $this->assertSame('Rowena', $task->fresh()->operator_name);
    }

    public function test_it_will_not_close_without_a_name(): void
    {
        [$desk, , , $task] = $this->waitingToRelease();

        $this->actingAs($desk)->post("/products/release/{$task->id}", [])
            ->assertInvalid(['operator_name']);

        $this->assertNotSame('complete', $task->fresh()->status);
    }

    public function test_a_new_order_puts_the_step_on_the_right_desk(): void
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-0'.random_int(1000, 9999),
            'customer_name' => 'Pipeline Co', 'product_type' => 'round_neck',
            'quantity' => 10, 'due_date' => now()->addWeek(),
            'created_by' => $sales->id, 'status' => 'active',
        ]);
        $order->jobOrder()->create(['status' => 'draft', 'created_by' => $sales->id]);
        $order->refresh()->rebuildPipeline([], 'laser');

        $this->assertSame('inventory',
            $order->fresh()->tasks->firstWhere('department', 'Release to client')->approver_role);
    }
}
