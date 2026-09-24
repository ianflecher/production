<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RequiredProductionMaterialsTest extends TestCase
{
    use RefreshDatabase;

    private function order(): ProductionOrder
    {
        $user = User::factory()->create(['job_role' => 'sales', 'is_active' => true]);
        $this->actingAs($user);
        $order = ProductionOrder::create(['order_number' => 'TEST-MATERIALS', 'customer_name' => 'Test', 'product_type' => 'round_neck', 'quantity' => 10, 'due_date' => now()->addWeeks(2), 'created_by' => $user->id, 'status' => 'active']);
        $order->jobOrder()->create(['created_by' => $user->id, 'status' => 'draft']);
        return $order;
    }

    public function test_missing_or_zero_quantities_are_rejected(): void
    {
        $order = $this->order();
        foreach ([[], ['raw_material_qty' => [0]], ['raw_material_qty' => [2 => 1]]] as $quantities) {
            $this->post(route('job-orders.production.update', $order), ['raw_materials' => ['Cotton']] + $quantities)
                ->assertSessionHasErrors();
        }
        $this->assertEmpty($order->jobOrder->fresh()->rawMaterialsList());
    }

    public function test_approval_and_override_cannot_complete_a_pack_without_materials(): void
    {
        $order = $this->order();
        $task = $order->tasks()->create(['department' => 'Tech pack', 'stage' => 2, 'sequence' => 1, 'status' => 'for_checking', 'approver_role' => 'leader']);
        foreach (['approve', 'forceComplete'] as $action) {
            try {
                $task->$action();
                $this->fail('Empty materials must block completion.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('raw_materials', $error->errors());
            }
            $this->assertSame('for_checking', $task->fresh()->status);
        }
    }
}
