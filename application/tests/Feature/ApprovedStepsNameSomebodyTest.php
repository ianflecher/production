<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * No line of the pipeline says a step was done by nobody.
 *
 * Steps that are closed by an approval are worked at no station, so nothing
 * ever wrote a name against them and the pipeline printed "—" under ASSIGNED
 * TO. Every other line names a person; these were blank precisely where
 * somebody made a decision.
 */
class ApprovedStepsNameSomebodyTest extends TestCase
{
    use RefreshDatabase;

    private function order(User $sales): ProductionOrder
    {
        return ProductionOrder::create([
            'order_number' => 'IC2026-0'.random_int(1000, 9999),
            'customer_name' => 'Signature Co', 'product_type' => 'round_neck',
            'quantity' => 12, 'due_date' => now()->addWeek(),
            'created_by' => $sales->id, 'status' => 'active',
        ]);
    }

    public function test_approving_the_sample_records_who_approved_it(): void
    {
        $sales = User::factory()->create([
            'job_role' => User::ROLE_SALES, 'is_active' => true, 'name' => 'Nasser',
        ]);
        $order = $this->order($sales);

        $task = Task::create([
            'production_order_id' => $order->id,
            'department' => 'Produce sample for client',
            'sequence' => 13, 'stage' => 9, 'status' => 'for_checking',
            'approver_role' => 'sales', 'submitted_at' => now(),
        ]);

        $this->actingAs($sales)->post("/tasks/{$task->id}/approve");

        $this->assertSame('Nasser', $task->fresh()->operator_name);
    }

    public function test_the_approver_does_not_replace_the_person_who_did_the_work(): void
    {
        // The pipeline reads operator_name FIRST, so stamping the approver on
        // a step somebody actually worked would quietly credit the officer who
        // nodded at the layout instead of the artist who drew it.
        $sales = User::factory()->create([
            'job_role' => User::ROLE_SALES, 'is_active' => true, 'name' => 'Nasser',
        ]);
        $artist = User::factory()->create([
            'job_role' => User::JOB_ARTIST, 'is_active' => true, 'name' => 'Maru',
        ]);
        $order = $this->order($sales);

        $task = Task::create([
            'production_order_id' => $order->id,
            'department' => 'Layout',
            'sequence' => 1, 'stage' => 1, 'status' => 'for_checking',
            'approver_role' => 'sales', 'assigned_to' => $artist->id,
            'submitted_at' => now(),
        ]);

        $this->actingAs($sales)->post("/tasks/{$task->id}/approve");

        $this->assertNull($task->fresh()->operator_name,
            'the artist on the step must keep the credit for it');
        $this->assertSame($artist->id, $task->fresh()->assigned_to);
    }

    public function test_a_name_recorded_on_the_floor_is_never_overwritten(): void
    {
        // Who held the tools outranks who signed it off.
        $leader = User::factory()->create([
            'job_role' => User::ROLE_LEADER, 'is_active' => true, 'name' => 'Maam Carla',
        ]);
        $order = $this->order($leader);

        $task = Task::create([
            'production_order_id' => $order->id,
            'department' => 'Sewing',
            'sequence' => 11, 'stage' => 7, 'status' => 'for_checking',
            'approver_role' => 'leader', 'operator_name' => 'Marites Bautista',
            'submitted_at' => now(),
        ]);

        $this->actingAs($leader)->post("/tasks/{$task->id}/approve");

        $this->assertSame('Marites Bautista', $task->fresh()->operator_name);
    }
}
