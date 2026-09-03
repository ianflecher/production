<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\MaterialRequest;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The desk issues what the job asked for.
 *
 * A request used to carry a material NAME and nothing else, so how much went
 * out was a free box at the desk with only the shelf to argue with it: a
 * hundred blanks against an order for fifty-five was a normal morning, and the
 * only way back was returning the difference afterwards.
 *
 * The job order now says how much of each material it needs, and that number
 * is what goes out.
 */
class MaterialRequestAmountTest extends TestCase
{
    use RefreshDatabase;

    private function shop(?float $needs): array
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $desk = User::factory()->create(['job_role' => 'Raw materials', 'is_active' => true, 'name' => 'Supply Desk']);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-0'.random_int(1000, 9999), 'customer_name' => 'Fifty Five Co',
            'product_type' => 'round_neck', 'quantity' => 55,
            'due_date' => now()->addWeek(), 'created_by' => $sales->id, 'status' => 'active',
        ]);

        $item = InventoryItem::create([
            'name' => 'Cotton shirt blank '.random_int(1000, 9999),
            'unit' => 'pcs', 'quantity' => 500,
        ]);

        $req = MaterialRequest::create([
            'production_order_id' => $order->id,
            'material' => $item->name,
            'status' => 'pending',
            'requested_quantity' => $needs,
        ]);

        return [$desk, $item, $req];
    }

    /**
     * The shelf gives what it has, and the job keeps waiting for the rest.
     *
     * A job wanting thirty-nine and a shelf holding eight used to be a choice
     * between issuing nothing and closing the request on the first handover -
     * so eight went out and the job was marked supplied, still needing
     * thirty-one that nobody was now asking for.
     */
    public function test_a_part_of_what_is_owed_can_go_out_and_the_rest_stays_owed(): void
    {
        [$desk, $item, $req] = $this->shop(39);

        $this->actingAs($desk)->post("/material-requests/{$req->id}/approve", [
            'inventory_item_id' => $item->id,
            'quantity' => 8,
            'operator_name' => 'Ian',
        ])->assertRedirect();

        $req = $req->fresh();

        $this->assertSame(8.0, (float) $req->issued_quantity, 'eight went out');
        $this->assertSame('pending', $req->status, 'the job is still owed thirty-one');
        $this->assertSame(492.0, (float) $item->fresh()->quantity, 'only eight left the shelf');
    }

    public function test_the_handovers_add_up_until_the_job_has_it_all(): void
    {
        [$desk, $item, $req] = $this->shop(39);

        foreach ([8, 20] as $handover) {
            $this->actingAs($desk)->post("/material-requests/{$req->id}/approve", [
                'inventory_item_id' => $item->id,
                'quantity' => $handover,
                'operator_name' => 'Ian',
            ])->assertRedirect();
        }

        $this->assertSame(28.0, (float) $req->fresh()->issued_quantity, 'the second did not erase the first');
        $this->assertSame('pending', $req->fresh()->status);

        // The last of it.
        $this->actingAs($desk)->post("/material-requests/{$req->id}/approve", [
            'inventory_item_id' => $item->id,
            'quantity' => 11,
            'operator_name' => 'Ian',
        ])->assertRedirect();

        $this->assertSame(39.0, (float) $req->fresh()->issued_quantity);
        $this->assertSame('approved', $req->fresh()->status, 'the job has everything it asked for');
        $this->assertSame(461.0, (float) $item->fresh()->quantity, '39 of 500 gone in three handovers');
    }

    public function test_a_last_handover_cannot_overshoot_what_is_left_owed(): void
    {
        [$desk, $item, $req] = $this->shop(39);

        $this->actingAs($desk)->post("/material-requests/{$req->id}/approve", [
            'inventory_item_id' => $item->id, 'quantity' => 30, 'operator_name' => 'Ian',
        ])->assertRedirect();

        // Nine are owed; somebody types fifty.
        $this->actingAs($desk)->post("/material-requests/{$req->id}/approve", [
            'inventory_item_id' => $item->id, 'quantity' => 50, 'operator_name' => 'Ian',
        ])->assertRedirect();

        $this->assertSame(39.0, (float) $req->fresh()->issued_quantity, 'trimmed to what was owed');
        $this->assertSame('approved', $req->fresh()->status);
        $this->assertSame(461.0, (float) $item->fresh()->quantity, 'the shelf did not lose fifty');
    }

    public function test_the_job_says_how_much_and_that_is_what_goes_out(): void
    {
        [$desk, $item, $req] = $this->shop(55);

        // The desk posts a hundred anyway — an old page, a stale tab, or
        // somebody who found the box. Fifty-five is what the job asked for.
        $this->actingAs($desk)->post("/material-requests/{$req->id}/approve", [
            'inventory_item_id' => $item->id,
            'quantity' => 100,
            'operator_name' => 'Ian',
        ])->assertRedirect();

        $this->assertSame('55.00', $req->fresh()->issued_quantity);
        $this->assertSame(445.0, (float) $item->fresh()->quantity, 'only what the job needed left the shelf');
    }

    public function test_what_was_asked_for_survives_being_issued(): void
    {
        [$desk, $item, $req] = $this->shop(55);

        $this->actingAs($desk)->post("/material-requests/{$req->id}/approve", [
            'inventory_item_id' => $item->id,
            'operator_name' => 'Ian',
        ])->assertRedirect();

        // Issuing used to write over the request's own quantity, so afterwards
        // nothing remembered what the job had asked for.
        $this->assertSame('55.00', $req->fresh()->requested_quantity);
    }

    public function test_a_material_with_no_stated_amount_is_still_typed_in(): void
    {
        [$desk, $item, $req] = $this->shop(null);

        $this->actingAs($desk)->post("/material-requests/{$req->id}/approve", [
            'inventory_item_id' => $item->id,
            'quantity' => 12,
            'operator_name' => 'Ian',
        ])->assertRedirect();

        $this->assertSame('12.00', $req->fresh()->issued_quantity);

        // And it is still required, so nothing goes out on a blank.
        [$desk2, $item2, $req2] = $this->shop(null);
        $this->actingAs($desk2)->post("/material-requests/{$req2->id}/approve", [
            'inventory_item_id' => $item2->id,
            'operator_name' => 'Ian',
        ])->assertSessionHasErrors('quantity');
    }
}
