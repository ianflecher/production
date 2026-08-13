<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\MaterialRequest;
use App\Models\ProductionOrder;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Materials that went out but were not used have to come back.
 *
 * A request says WHICH material a job needs, never how many, so the desk
 * types the amount by hand — and handing out 100 when the job wanted 55 is
 * a normal morning. The only way back used to be a blank "correction" on
 * the item, which left the request still claiming 100 went to that order
 * and the 45 explained by nothing.
 */
class ReturnUnusedMaterialsTest extends TestCase
{
    use RefreshDatabase;

    /** An order with 100 already issued against it, of which 55 was needed. */
    private function issued(float $qty = 100): array
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $desk = User::factory()->create(['job_role' => 'Raw materials', 'is_active' => true, 'name' => 'Supply Desk']);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-0'.random_int(1000, 9999), 'customer_name' => 'Overdrawn Co',
            'product_type' => 'round_neck', 'quantity' => 55,
            'due_date' => now()->addWeek(), 'created_by' => $sales->id, 'status' => 'active',
        ]);

        $item = InventoryItem::create(['name' => 'Cotton shirt blank', 'unit' => 'pcs', 'quantity' => 500]);
        $req = MaterialRequest::create([
            'production_order_id' => $order->id, 'material' => 'Cotton shirt blank', 'status' => 'pending',
        ]);

        $this->actingAs($desk)->post("/material-requests/{$req->id}/approve", [
            'inventory_item_id' => $item->id,
            'quantity' => $qty,
            'operator_name' => 'Aling Nena',
        ])->assertSessionHasNoErrors();

        return [$desk, $req->fresh(), $item->fresh()];
    }

    public function test_the_unused_part_goes_back_on_the_shelf(): void
    {
        [$desk, $req, $item] = $this->issued();

        $this->assertEqualsWithDelta(400.0, (float) $item->quantity, 0.01, 'setup: 100 of 500 went out');

        $this->actingAs($desk)->post("/material-requests/{$req->id}/return", [
            'quantity' => 45,
            'operator_name' => 'Aling Nena',
            'note' => 'issued too many',
        ])->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(445.0, (float) $item->fresh()->quantity, 0.01,
            'the 45 that were never used must be back in stock');
    }

    public function test_the_job_is_only_charged_for_what_it_used(): void
    {
        [$desk, $req] = $this->issued();

        $this->actingAs($desk)->post("/material-requests/{$req->id}/return", [
            'quantity' => 45, 'operator_name' => 'Aling Nena',
        ]);

        $this->assertEqualsWithDelta(55.0, (float) $req->fresh()->quantity, 0.01,
            'costing must read what the job consumed, not what left the store room');
    }

    public function test_the_return_is_logged_against_the_order_and_the_person(): void
    {
        [$desk, $req] = $this->issued();

        $this->actingAs($desk)->post("/material-requests/{$req->id}/return", [
            'quantity' => 45, 'operator_name' => 'Aling Nena', 'note' => 'issued too many',
        ]);

        $move = StockMovement::where('reason', 'returned')->latest('id')->firstOrFail();

        $this->assertTrue($move->isIn());
        $this->assertEqualsWithDelta(45.0, (float) $move->quantity, 0.01);
        $this->assertSame($req->production_order_id, $move->production_order_id,
            'a return has to point at the order it came back from');
        $this->assertSame('Aling Nena', $move->operator());
        $this->assertStringContainsString('issued too many', (string) $move->note);
    }

    public function test_you_cannot_hand_back_more_than_went_out(): void
    {
        [$desk, $req, $item] = $this->issued();

        $this->actingAs($desk)->post("/material-requests/{$req->id}/return", [
            'quantity' => 140, 'operator_name' => 'Aling Nena',
        ])->assertInvalid(['quantity']);

        $this->assertEqualsWithDelta(400.0, (float) $item->fresh()->quantity, 0.01,
            'a refused return must not move stock');
    }

    public function test_returning_twice_cannot_exceed_the_issued_amount(): void
    {
        [$desk, $req, $item] = $this->issued();

        $this->actingAs($desk)->post("/material-requests/{$req->id}/return",
            ['quantity' => 45, 'operator_name' => 'Aling Nena']);

        // 55 is left on the request, so 60 is now too much even though the
        // original issue was 100.
        $this->actingAs($desk)->post("/material-requests/{$req->id}/return",
            ['quantity' => 60, 'operator_name' => 'Aling Nena'])->assertInvalid(['quantity']);

        $this->assertEqualsWithDelta(445.0, (float) $item->fresh()->quantity, 0.01);
    }

    public function test_the_person_returning_must_say_who_they_are(): void
    {
        [$desk, $req] = $this->issued();

        $this->actingAs($desk)->post("/material-requests/{$req->id}/return", ['quantity' => 45])
            ->assertInvalid(['operator_name']);
    }

    public function test_nothing_can_be_returned_against_a_rejected_request(): void
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $desk = User::factory()->create(['job_role' => 'Raw materials', 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-07777', 'customer_name' => 'Nothing Co',
            'product_type' => 'round_neck', 'quantity' => 5,
            'due_date' => now()->addWeek(), 'created_by' => $sales->id, 'status' => 'active',
        ]);
        $req = MaterialRequest::create([
            'production_order_id' => $order->id, 'material' => 'Thread', 'status' => 'rejected',
        ]);

        $this->actingAs($desk)->post("/material-requests/{$req->id}/return",
            ['quantity' => 1, 'operator_name' => 'Aling Nena'])->assertForbidden();
    }

    public function test_the_desk_is_offered_the_way_back(): void
    {
        [$desk, $req] = $this->issued();

        $this->actingAs($desk)->get('/material-requests')
            ->assertOk()
            ->assertSee('Return unused')
            ->assertSee(route('inventory.requests.return', $req), false);
    }

    public function test_an_outsider_cannot_put_stock_back(): void
    {
        [, $req] = $this->issued();
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $this->actingAs($artist)->post("/material-requests/{$req->id}/return",
            ['quantity' => 45, 'operator_name' => 'Sneaky'])->assertForbidden();
    }
}
