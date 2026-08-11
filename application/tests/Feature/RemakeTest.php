<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Remakes: a wrong colour, a damaged panel, a seam that failed the check.
 *
 * The pieces are made again, so it is a real job on the floor — but only the
 * making. The design is drawn, approved and exported already, and the client
 * is waiting on garments they have paid for, so there is nothing to show them
 * and nothing to charge.
 */
class RemakeTest extends TestCase
{
    use RefreshDatabase;

    private function original(): ProductionOrder
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $this->actingAs($sales)->post('/orders', [
            'order_number' => 'IC2026-08888',
            'client_name' => 'Remake', 'client_last_name' => 'Co',
            'client_contact' => '0917-000-2222',
            'client_office_address' => 'Angeles City', 'client_delivery_address' => 'Angeles City',
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 20, 'L' => 10],
        ]);

        $order = ProductionOrder::where('order_number', 'IC2026-08888')->firstOrFail();
        // Creating the order already makes its job order — fill that one in
        // rather than making a second the relation will never return.
        $order->jobOrder()->updateOrCreate(['production_order_id' => $order->id], [
            'status' => 'sent_to_artist', 'created_by' => $sales->id,
            'print_type' => 'full_sublimation', 'printer' => 'atexco',
            'fabric' => 'Dri-fit micro mesh', 'neck' => 'Printed ribbings',
            // Something the FLOOR recorded, which must not be inherited.
            'neckbond_sewer' => 'Marites Bautista',
        ]);
        $order->refresh()->rebuildPipeline([], 'laser');

        return $order->fresh();
    }

    private function leader(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);
    }

    private function remake(ProductionOrder $order, int $qty = 5): ProductionOrder
    {
        $this->actingAs($this->leader())->post("/orders/{$order->id}/replacement", [
            'replacement_reason' => 'Damaged fabric on '.$qty.' pcs',
            'quantity' => $qty,
            'due_date' => now()->addDays(6)->toDateString(),
        ]);

        return ProductionOrder::where('replaces_order_id', $order->id)->latest('id')->firstOrFail();
    }

    public function test_a_remake_is_production_only_from_the_printer_to_inventory(): void
    {
        $remake = $this->remake($this->original());

        $departments = $remake->tasks()->orderBy('sequence')->pluck('department');

        $this->assertSame('Printer', $departments->first(),
            'a remake starts at the printer — the design is already done');
        $this->assertSame('Inventory', $departments->last(),
            'and ends when the pieces are counted in');

        // Nothing from the design half, and no second trip past the client.
        foreach (['Layout', 'Final mockup', 'Production template', 'Export',
            'Produce sample for client', 'Release to client'] as $gone) {
            $this->assertFalse($departments->contains($gone), "$gone should not be on a remake");
        }
    }

    public function test_the_floor_can_start_it_straight_away(): void
    {
        $remake = $this->remake($this->original());

        $this->assertSame('ready', $remake->tasks()->where('department', 'Printer')->value('status'),
            'nobody has to approve a design that was approved the first time');
    }

    public function test_it_carries_the_specs_but_not_the_first_run_seam_record(): void
    {
        $remake = $this->remake($this->original());

        $this->assertSame('Dri-fit micro mesh', $remake->jobOrder->fabric, 'same garment, same spec');
        $this->assertSame('Printed ribbings', $remake->jobOrder->neck);
        $this->assertNull($remake->jobOrder->neckbond_sewer,
            'who sewed the first run says nothing about who sews this one');
    }

    public function test_it_costs_nothing_and_points_at_what_it_replaces(): void
    {
        $order = $this->original();
        $remake = $this->remake($order, 5);

        $this->assertSame('0.00', (string) $remake->total_price, 'the work is being done twice and paid once');
        $this->assertSame(5, (int) $remake->quantity);
        $this->assertSame(5, (int) $remake->items()->sum('quantity'));
        $this->assertTrue($remake->isReplacement());
        $this->assertSame($order->id, $remake->replaces->id);
        $this->assertStringContainsString('Damaged fabric', $remake->replacement_reason);
    }

    public function test_it_cannot_remake_more_pieces_than_were_ordered(): void
    {
        $order = $this->original();

        $this->actingAs($this->leader())
            ->post("/orders/{$order->id}/replacement", [
                'replacement_reason' => 'Everything and then some',
                'quantity' => (int) $order->quantity + 1,
                'due_date' => now()->addDays(6)->toDateString(),
            ])
            ->assertSessionHasErrors('quantity');
    }

    public function test_only_a_leader_may_order_one(): void
    {
        $order = $this->original();
        $sales = User::find($order->created_by);

        $this->actingAs($sales)
            ->post("/orders/{$order->id}/replacement", [
                'replacement_reason' => 'Wrong colour on 3 pcs',
                'quantity' => 3,
                'due_date' => now()->addDays(6)->toDateString(),
            ])
            ->assertForbidden();
    }
}
