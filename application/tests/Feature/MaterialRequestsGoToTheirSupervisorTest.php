<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Material requests go to the raw materials supervisor.
 *
 * She holds the fabric — the bolts, by the kilo — and decides what comes off
 * which shelf. The alert went to the supply-chain role, which is a different
 * desk and a different person, so the work landed on a queue nobody was
 * watching for it.
 *
 * The desk still reads the same queue; it just no longer gets the alert for
 * work that is hers to hand out.
 */
class MaterialRequestsGoToTheirSupervisorTest extends TestCase
{
    use RefreshDatabase;

    private function sales(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
    }

    private function orderNeedingMaterials(User $sales): ProductionOrder
    {
        $this->actingAs($sales)->post('/orders', [
            'order_number' => 'IC2026-07070',
            'client_name' => 'Material', 'client_last_name' => 'Co',
            'client_contact' => '0917-000-0000', 'client_address' => 'Angeles City',
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10],
        ]);

        $order = ProductionOrder::where('order_number', 'IC2026-07070')->firstOrFail();

        $order->jobOrder->update(['raw_materials' => ['AIRCOOL 11X1 WHT', 'ribbing']]);
        $order->refresh()->syncMaterialRequests();

        return $order;
    }

    /* ---------------- who is told ---------------- */

    public function test_the_raw_materials_supervisor_is_told(): void
    {
        $supervisor = User::factory()->create([
            'name' => 'Maam Khaye',
            'job_role' => User::JOB_RAW_MATERIALS_SUPERVISOR,
            'is_active' => true,
        ]);

        $this->orderNeedingMaterials($this->sales());

        $this->assertTrue(
            AppNotification::pendingFor($supervisor)->contains(fn ($n) => str_contains($n->title, 'material request')),
            'the requests were raised and she was not told'
        );
    }

    /** And the supply-chain desk is not, because the work moved. */
    public function test_the_supply_chain_desk_is_no_longer_told(): void
    {
        $supplyChain = User::factory()->create([
            'job_role' => User::JOB_SUPPLY_CHAIN, 'is_active' => true,
        ]);

        $this->orderNeedingMaterials($this->sales());

        $this->assertFalse(
            AppNotification::pendingFor($supplyChain)->contains(fn ($n) => str_contains($n->title, 'material request')),
            'the alert still goes to the desk it was moved off'
        );
    }

    /* ---------------- what she may do ---------------- */

    /** Being told about work she cannot open would be worse than not being told. */
    public function test_she_can_open_the_queue_she_is_told_about(): void
    {
        $supervisor = User::factory()->create([
            'job_role' => User::JOB_RAW_MATERIALS_SUPERVISOR, 'is_active' => true,
        ]);

        $this->assertTrue($supervisor->canManageInventory());

        $this->actingAs($supervisor)->get(route('inventory.requests'))->assertOk();
        $this->actingAs($supervisor)->get(route('inventory.index'))->assertOk();
    }

    /** The desk keeps its own access — the queue is shared, only the alert moved. */
    public function test_the_raw_materials_desk_still_reads_the_queue(): void
    {
        $desk = User::factory()->create(['job_role' => 'raw materials', 'is_active' => true]);

        $this->actingAs($desk)->get(route('inventory.requests'))->assertOk();
    }

    /** And she is not handed the finished-goods shelves, which are another desk. */
    public function test_she_is_not_given_the_products_inventory(): void
    {
        $supervisor = User::factory()->create([
            'job_role' => User::JOB_RAW_MATERIALS_SUPERVISOR, 'is_active' => true,
        ]);

        $this->assertFalse($supervisor->canManageProducts());
    }

    /** The position can be picked when hiring, not only typed into the database. */
    public function test_the_position_can_be_appointed(): void
    {
        $this->assertContains(
            User::JOB_RAW_MATERIALS_SUPERVISOR,
            array_keys(User::officePositions())
        );
    }
}
