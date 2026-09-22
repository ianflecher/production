<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\MaterialRequest;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The officer picks the material off the shelf, not out of their head.
 *
 * Raw materials were a text box. What was typed went straight onto the job
 * order and the supply desk had to work out at the far end which row it meant —
 * "QA700" is ten QUIANAs to choose between, and "COTTON HOODIE" is nothing at
 * all, because the shelf keeps "AAA HOODIE BLACK - 2XL" and its brothers.
 *
 * A name picked off the shelf is the row that gets deducted, and there is
 * nothing left to work out. Anything the shop does not stock yet is still
 * typed, under Other, because a form that can only name what already exists
 * cannot take an order for something new.
 */
class TheOfficerPicksOffTheShelfTest extends TestCase
{
    use RefreshDatabase;

    private function officer(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
    }

    private function order(User $officer): ProductionOrder
    {
        $this->actingAs($officer)->post('/orders', [
            'order_number' => 'IC2026-09077',
            'client_name' => 'Off', 'client_last_name' => 'Shelf',
            'client_contact' => '0917-000-0000', 'client_address' => 'Angeles City',
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10],
        ]);

        return ProductionOrder::where('order_number', 'IC2026-09077')->firstOrFail();
    }

    private function stock(): void
    {
        foreach (['QUIANA (140GSM) BLK', 'QUIANA (140GSM) WHT'] as $name) {
            InventoryItem::create(['name' => $name, 'category' => 'FABRIC', 'unit' => 'KG',
                'kind' => InventoryItem::KIND_FABRIC, 'quantity' => 40]);
        }

        InventoryItem::create(['name' => 'AAA HOODIE BLACK - L', 'category' => 'HOODIE', 'unit' => 'PC',
            'kind' => InventoryItem::KIND_READY_MADE, 'quantity' => 12]);
    }

    /* ---------------- the form offers the shelves ---------------- */

    public function test_the_form_offers_both_shelves_and_a_way_out(): void
    {
        $officer = $this->officer();
        $order = $this->order($officer);
        $this->stock();

        $html = $this->actingAs($officer)
            ->get(route('job-orders.production', $order))
            ->assertOk()->getContent();

        // Both shelves reach the page, each under its own name.
        $this->assertStringContainsString('QUIANA (140GSM) BLK', $html);
        $this->assertStringContainsString('AAA HOODIE BLACK - L', $html);
        $this->assertStringContainsString('"fabric"', $html);
        $this->assertStringContainsString('"ready_made"', $html);

        // A picker that writes into the box, and the box that posts.
        $this->assertStringContainsString('class="rm-pick"', $html);

        // The shelf is asked first, because it decides what the picker holds.
        $kind = strpos($html, 'class="rm-kind"');
        $pick = strpos($html, 'class="rm-pick"');

        $this->assertNotFalse($kind);
        $this->assertNotFalse($pick);
        $this->assertLessThan($pick, $kind, 'the material is asked before the shelf it comes off');
        $this->assertStringContainsString('name="raw_materials[]"', $html);
        $this->assertStringContainsString('Other', $html);
    }

    /** The lists are the shop's own stock, each on its own shelf. */
    public function test_each_shelf_offers_only_its_own(): void
    {
        $officer = $this->officer();
        $order = $this->order($officer);
        $this->stock();

        $html = $this->actingAs($officer)->get(route('job-orders.production', $order))->getContent();

        preg_match('/const RM_SHELVES = (\{.*?\});/s', $html, $m);

        $this->assertNotEmpty($m, 'the shelves never reached the page');

        $shelves = json_decode($m[1], true);
        $names = fn (string $kind) => array_column($shelves[$kind], 'n');

        $this->assertContains('QUIANA (140GSM) WHT', $names('fabric'));
        $this->assertNotContains('AAA HOODIE BLACK - L', $names('fabric'));
        $this->assertContains('AAA HOODIE BLACK - L', $names('ready_made'));
        $this->assertNotContains('QUIANA (140GSM) WHT', $names('ready_made'));

        // And what is left of each, so nothing is promised off an empty shelf.
        $quiana = collect($shelves['fabric'])->firstWhere('n', 'QUIANA (140GSM) WHT');

        $this->assertSame('40', $quiana['q']);
        $this->assertSame('KG', $quiana['u']);
        $this->assertFalse($quiana['o']);
    }

    /** A material at zero is marked out, not shown as a quantity of none. */
    public function test_an_empty_shelf_row_is_marked_as_one(): void
    {
        $officer = $this->officer();
        $order = $this->order($officer);
        $this->stock();

        InventoryItem::create(['name' => 'RIBBING BLACK', 'category' => 'FABRIC', 'unit' => 'KG',
            'kind' => InventoryItem::KIND_FABRIC, 'quantity' => 0]);

        $html = $this->actingAs($officer)->get(route('job-orders.production', $order))->getContent();

        preg_match('/const RM_SHELVES = (\{.*?\});/s', $html, $m);
        $shelves = json_decode($m[1], true);

        $this->assertTrue(collect($shelves['fabric'])->firstWhere('n', 'RIBBING BLACK')['o']);
        $this->assertStringContainsString('none left', $html);
    }

    /* ---------------- and what is picked is what is deducted ---------- */

    /**
     * The whole point. A row picked here leaves the supply desk nothing to
     * work out: one candidate, so no question is asked at all.
     */
    public function test_a_picked_row_leaves_the_desk_nothing_to_decide(): void
    {
        $officer = $this->officer();
        $order = $this->order($officer);
        $this->stock();

        $this->actingAs($officer)->post(route('job-orders.production.update', $order), [
            'raw_materials' => ['QUIANA (140GSM) WHT'],
            'raw_material_kind' => [InventoryItem::KIND_FABRIC],
            'raw_material_qty' => [12],
            'cutting_type' => 'manual',
            'fabric_press' => 'small_press',
        ])->assertSessionHasNoErrors();

        // The requests are raised when the leader opens the Raw materials step;
        // this walk stops short of that, so it asks for them here.
        $order->fresh()->syncMaterialRequests();

        $request = MaterialRequest::where('material', 'QUIANA (140GSM) WHT')->firstOrFail();

        $this->assertSame(1, $request->stockCandidates()->count());
        $this->assertSame('QUIANA (140GSM) WHT', $request->stockItem()?->name);
    }

    /** Typed under Other, it still reaches the desk — as a question. */
    public function test_something_not_stocked_yet_still_goes_through(): void
    {
        $officer = $this->officer();
        $order = $this->order($officer);
        $this->stock();

        $this->actingAs($officer)->post(route('job-orders.production.update', $order), [
            'raw_materials' => ['REFLECTIVE PIPING 3M'],
            'raw_material_kind' => [InventoryItem::KIND_READY_MADE],
            'raw_material_qty' => [4],
            'cutting_type' => 'manual',
            'fabric_press' => 'small_press',
        ])->assertSessionHasNoErrors();

        // The requests are raised when the leader opens the Raw materials step;
        // this walk stops short of that, so it asks for them here.
        $order->fresh()->syncMaterialRequests();

        $request = MaterialRequest::where('material', 'REFLECTIVE PIPING 3M')->firstOrFail();

        $this->assertSame(InventoryItem::KIND_READY_MADE, $request->kind);
        $this->assertTrue($request->stockCandidates()->isEmpty());
    }

    /** A job order already written keeps what it says, picked or typed. */
    public function test_what_was_already_written_comes_back(): void
    {
        $officer = $this->officer();
        $order = $this->order($officer);
        $this->stock();

        $order->jobOrder->update([
            'raw_materials' => ['QUIANA (140GSM) BLK', 'SOMETHING NOBODY STOCKS'],
            'raw_material_kinds' => [
                'QUIANA (140GSM) BLK' => InventoryItem::KIND_FABRIC,
                'SOMETHING NOBODY STOCKS' => InventoryItem::KIND_FABRIC,
            ],
        ]);

        $html = $this->actingAs($officer)
            ->get(route('job-orders.production', $order))->assertOk()->getContent();

        $this->assertStringContainsString('value="QUIANA (140GSM) BLK"', $html);
        $this->assertStringContainsString('value="SOMETHING NOBODY STOCKS"', $html);
    }
}
