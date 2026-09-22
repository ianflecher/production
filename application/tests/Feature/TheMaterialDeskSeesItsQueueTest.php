<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\MaterialRequest;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The material desks open on their own queue.
 *
 * Both keepers arrived each morning at a page with a button on it — "open
 * material requests" — and three lines of filler underneath. Whether anything
 * was waiting, for whom, and by when was on the other side of that button, so
 * the only way to find out was to go and look.
 *
 * The numbers were there in the code all along and were never drawn; worse,
 * they counted both shelves, so the supervisor was told about requests she
 * cannot open and stock she does not keep.
 *
 * And the queue now has a door of its own in the sidebar, carrying the count,
 * rather than being reachable only through the dashboard or the stock page.
 */
class TheMaterialDeskSeesItsQueueTest extends TestCase
{
    use RefreshDatabase;

    private function supervisor(): User
    {
        return User::factory()->create([
            'name' => 'Maam Khaye',
            'job_role' => User::JOB_RAW_MATERIALS_SUPERVISOR,
            'is_active' => true,
        ]);
    }

    private function desk(): User
    {
        return User::factory()->create([
            'name' => 'Raw Materials', 'job_role' => 'raw materials', 'is_active' => true,
        ]);
    }

    /** An order asking for one fabric and one ready-made item. */
    private function orderWithBoth(): ProductionOrder
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $this->actingAs($sales)->post('/orders', [
            'order_number' => 'IC2026-09099',
            'client_name' => 'Two', 'client_last_name' => 'Shelves',
            'client_contact' => '0917-000-0000', 'client_address' => 'Angeles City',
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10],
        ]);

        $order = ProductionOrder::where('order_number', 'IC2026-09099')->firstOrFail();

        $order->jobOrder->update([
            'raw_materials' => ['AIRCOOL 11X1 WHT', 'CAP'],
            'raw_material_kinds' => [
                'AIRCOOL 11X1 WHT' => InventoryItem::KIND_FABRIC,
                'CAP' => InventoryItem::KIND_READY_MADE,
            ],
        ]);

        $order->refresh()->syncMaterialRequests();

        return $order;
    }

    /* ---------------- the queue is on the front page ---------------- */

    public function test_the_desk_opens_on_what_is_waiting(): void
    {
        $supervisor = $this->supervisor();
        $order = $this->orderWithBoth();

        $html = $this->actingAs($supervisor)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('Waiting on you', $html);
        $this->assertStringContainsString('AIRCOOL 11X1 WHT', $html);
        $this->assertStringContainsString($order->order_number, $html);
        $this->assertStringContainsString('Two Shelves', $html);
    }

    /** And not on the other keeper's. */
    public function test_each_keeper_sees_only_their_own_shelf_waiting(): void
    {
        $supervisor = $this->supervisor();
        $desk = $this->desk();
        $this->orderWithBoth();

        $hers = $this->actingAs($supervisor)->get(route('dashboard'))->getContent();
        $this->assertStringContainsString('AIRCOOL 11X1 WHT', $hers);
        $this->assertStringNotContainsString('>CAP<', $hers);

        $theirs = $this->actingAs($desk)->get(route('dashboard'))->getContent();
        $this->assertStringContainsString('CAP', $theirs);
        $this->assertStringNotContainsString('AIRCOOL 11X1 WHT', $theirs);
    }

    /**
     * The numbers above the queue were counting both shelves. The supervisor
     * was told she had two requests when one of them was the desk's.
     */
    public function test_the_numbers_count_one_shelf(): void
    {
        $supervisor = $this->supervisor();
        $this->orderWithBoth();

        InventoryItem::create(['name' => 'A FABRIC', 'category' => 'FABRIC', 'unit' => 'KG',
            'kind' => InventoryItem::KIND_FABRIC, 'quantity' => 5]);
        InventoryItem::create(['name' => 'A CAP', 'category' => 'CAP', 'unit' => 'PC',
            'kind' => InventoryItem::KIND_READY_MADE, 'quantity' => 5]);

        $html = $this->actingAs($supervisor)->get(route('dashboard'))->getContent();

        // One request on her shelf, and one material on it.
        $this->assertSame(1, preg_match(
            '#Material requests.*?>1<#s', $html
        ), 'the request count is not one');

        $this->assertSame(1, preg_match(
            '#Materials tracked.*?>1<#s', $html
        ), 'the stock count is not one');
    }

    /** With nothing waiting, it says so rather than showing an empty table. */
    public function test_an_empty_queue_says_so(): void
    {
        $html = $this->actingAs($this->supervisor())->get(route('dashboard'))->getContent();

        $this->assertStringContainsString('Nothing is waiting', $html);
    }

    /* ---------------- and has a door of its own ---------------- */

    public function test_the_queue_has_its_own_tab(): void
    {
        foreach ([$this->supervisor(), $this->desk()] as $keeper) {
            $html = $this->actingAs($keeper)->get(route('dashboard'))->getContent();

            $this->assertStringContainsString('Material Requests', $html, $keeper->job_role.' has no tab');
            $this->assertStringContainsString(route('inventory.requests'), $html);
        }
    }

    /** Somebody who keeps no shelf gets no tab. */
    public function test_an_artist_has_no_such_tab(): void
    {
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $this->assertStringNotContainsString(
            'Material Requests',
            $this->actingAs($artist)->get(route('dashboard'))->getContent()
        );
    }

    /* ---------------- approve and deduct, with nothing to choose ---------- */

    /**
     * Which stock a request comes out of is written on the request. It used to
     * be a dropdown of every material in the shop: one right answer, and a
     * thousand wrong ones that each deducted the wrong row.
     */
    public function test_there_is_no_material_to_choose(): void
    {
        $supervisor = $this->supervisor();
        $this->orderWithBoth();

        InventoryItem::create(['name' => 'AIRCOOL 11X1 WHT', 'category' => 'FABRIC', 'unit' => 'KG',
            'kind' => InventoryItem::KIND_FABRIC, 'quantity' => 50]);

        $html = $this->actingAs($supervisor)->get(route('inventory.requests'))->assertOk()->getContent();

        $this->assertStringNotContainsString('name="inventory_item_id"', $html);
        $this->assertStringNotContainsString('Select material', $html);
        // What it will come out of is shown instead.
        $this->assertStringContainsString('on the shelf', $html);
        $this->assertStringContainsString('Approve', $html);
    }

    public function test_approving_deducts_the_material_it_names(): void
    {
        $supervisor = $this->supervisor();
        $this->orderWithBoth();

        $fabric = InventoryItem::create(['name' => 'AIRCOOL 11X1 WHT', 'category' => 'FABRIC',
            'unit' => 'KG', 'kind' => InventoryItem::KIND_FABRIC, 'quantity' => 50]);

        // A decoy on the same shelf: the wrong one must not move.
        $decoy = InventoryItem::create(['name' => 'TASLAN H9 BLK', 'category' => 'FABRIC',
            'unit' => 'KG', 'kind' => InventoryItem::KIND_FABRIC, 'quantity' => 50]);

        $request = MaterialRequest::where('material', 'AIRCOOL 11X1 WHT')->firstOrFail();

        $this->actingAs($supervisor)
            ->post(route('inventory.requests.approve', $request), [
                'quantity' => 4,
                'operator_name' => 'Khaye',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('46.00', $fabric->fresh()->quantity);
        $this->assertSame('50.00', $decoy->fresh()->quantity, 'the wrong shelf was deducted');
        $this->assertSame($fabric->id, $request->fresh()->inventory_item_id);
        $this->assertSame('approved', $request->fresh()->status);
    }

    /** Spelling on a hand-typed stock sheet does not stop the deduction. */
    public function test_a_differently_spelt_row_is_still_the_same_material(): void
    {
        $supervisor = $this->supervisor();
        $this->orderWithBoth();

        $fabric = InventoryItem::create(['name' => 'aircool  11x1-wht', 'category' => 'FABRIC',
            'unit' => 'KG', 'kind' => InventoryItem::KIND_FABRIC, 'quantity' => 30]);

        $request = MaterialRequest::where('material', 'AIRCOOL 11X1 WHT')->firstOrFail();

        $this->actingAs($supervisor)
            ->post(route('inventory.requests.approve', $request), ['quantity' => 3, 'operator_name' => 'Khaye'])
            ->assertSessionHasNoErrors();

        $this->assertSame('27.00', $fabric->fresh()->quantity);
    }

    /**
     * The shop calls one fabric two things: a job order asks for QA700 and the
     * stock sheet files it under QUIANA. The desk used to bridge that by hand,
     * picking the right row out of the dropdown. With the dropdown gone the
     * pair has to be written down, and it is.
     */
    public function test_a_material_known_by_two_names_still_finds_its_stock(): void
    {
        $supervisor = $this->supervisor();
        $order = $this->orderWithBoth();

        $order->jobOrder->update([
            'raw_materials' => ['QA700'],
            'raw_material_kinds' => ['QA700' => InventoryItem::KIND_FABRIC],
        ]);
        $order->refresh()->syncMaterialRequests();

        $quiana = InventoryItem::create(['name' => 'QUIANA', 'category' => 'FABRIC',
            'unit' => 'KG', 'kind' => InventoryItem::KIND_FABRIC, 'quantity' => 80]);

        $request = MaterialRequest::where('material', 'QA700')->firstOrFail();

        $this->assertSame($quiana->id, $request->stockItem()?->id, 'QA700 did not find QUIANA');

        $this->actingAs($supervisor)
            ->post(route('inventory.requests.approve', $request), ['quantity' => 6, 'operator_name' => 'Khaye'])
            ->assertSessionHasNoErrors();

        $this->assertSame('74.00', $quiana->fresh()->quantity);
    }

    /** It works the other way round too: stock under QA700, job asking QUIANA. */
    public function test_the_pair_reads_both_ways(): void
    {
        $order = $this->orderWithBoth();

        $order->jobOrder->update([
            'raw_materials' => ['QUIANA'],
            'raw_material_kinds' => ['QUIANA' => InventoryItem::KIND_FABRIC],
        ]);
        $order->refresh()->syncMaterialRequests();

        $stock = InventoryItem::create(['name' => 'QA700', 'category' => 'FABRIC',
            'unit' => 'KG', 'kind' => InventoryItem::KIND_FABRIC, 'quantity' => 80]);

        $this->assertSame(
            $stock->id,
            MaterialRequest::where('material', 'QUIANA')->firstOrFail()->stockItem()?->id
        );
    }

    /** A material nobody has put on the shelf says so, and deducts nothing. */
    public function test_a_material_not_on_the_shelf_is_refused_plainly(): void
    {
        $supervisor = $this->supervisor();
        $this->orderWithBoth();

        $request = MaterialRequest::where('material', 'AIRCOOL 11X1 WHT')->firstOrFail();

        $this->actingAs($supervisor)
            ->post(route('inventory.requests.approve', $request), ['quantity' => 3, 'operator_name' => 'Khaye'])
            ->assertSessionHasErrors('quantity');

        $this->assertSame('pending', $request->fresh()->status);
    }

    /** And a request never reaches across to the other keeper's shelf. */
    public function test_it_cannot_deduct_the_other_shelf(): void
    {
        $supervisor = $this->supervisor();
        $this->orderWithBoth();

        // Same name, wrong shelf.
        InventoryItem::create(['name' => 'AIRCOOL 11X1 WHT', 'category' => 'CAP', 'unit' => 'PC',
            'kind' => InventoryItem::KIND_READY_MADE, 'quantity' => 99]);

        $request = MaterialRequest::where('material', 'AIRCOOL 11X1 WHT')->firstOrFail();

        $this->actingAs($supervisor)
            ->post(route('inventory.requests.approve', $request), ['quantity' => 3, 'operator_name' => 'Khaye'])
            ->assertSessionHasErrors('quantity');

        $this->assertSame('99.00', InventoryItem::where('kind', InventoryItem::KIND_READY_MADE)
            ->where('name', 'AIRCOOL 11X1 WHT')->value('quantity'));
    }
}
