<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\MaterialAlias;
use App\Models\MaterialRequest;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The job number on the raw materials desk opens the sheet.
 *
 * It went to the order's admin page. The desk issuing materials is reading
 * the package — the approved mockup, the template, the job order and the
 * production details — so every click landed a page short and they hunted
 * for the document from there. It is the same document the leader opens off
 * the approvals list, and the same route.
 *
 * The care is the order with no sheet behind it yet: that route answers 404,
 * and a dead link off a working list is worse than a plain one.
 */
class TheSupplyDeskOpensTheSheetTest extends TestCase
{
    use RefreshDatabase;

    private function supplyDesk(): User
    {
        return User::factory()->create(['job_role' => User::JOB_RAW_MATERIALS_SUPERVISOR, 'is_active' => true]);
    }

    private function order(bool $withSheet = true): ProductionOrder
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-0'.random_int(1000, 9999),
            'customer_name' => 'Supply Co',
            'product_type' => 'round_neck',
            'quantity' => 30,
            'due_date' => now()->addWeeks(2),
            'created_by' => $officer->id,
            'status' => 'active',
        ]);

        if ($withSheet) {
            $order->jobOrder()->create(['status' => 'sent_to_artist', 'created_by' => $officer->id]);
        }

        return $order->fresh();
    }

    private function request(ProductionOrder $order, string $status = 'pending'): MaterialRequest
    {
        return MaterialRequest::create([
            'production_order_id' => $order->id,
            'material' => 'Cotton blend',
            'status' => $status,
            'requested_quantity' => 30,
        ]);
    }

    /* ---------------- where the number goes ---------------- */

    public function test_a_pending_request_links_the_job_number_to_the_sheet(): void
    {
        $order = $this->order();
        $this->request($order);

        $this->actingAs($this->supplyDesk())
            ->get(route('inventory.requests'))
            ->assertOk()
            ->assertSee(route('orders.package', $order), false)
            ->assertDontSee(route('orders.show', $order).'"', false);
    }

    /** The decided list underneath it carries the same number, so it agrees. */
    public function test_a_decided_request_links_the_job_number_to_the_sheet(): void
    {
        $order = $this->order();
        $this->request($order, 'approved');

        $this->actingAs($this->supplyDesk())
            ->get(route('inventory.requests'))
            ->assertOk()
            ->assertSee(route('orders.package', $order), false);
    }

    /**
     * The one that would be a dead link. No sheet, no package — that route
     * answers 404 — so the number keeps its old destination rather than
     * taking the desk off the page they were working.
     */
    public function test_an_order_with_no_sheet_yet_still_links_somewhere_real(): void
    {
        $order = $this->order(withSheet: false);
        $this->request($order);

        $this->assertFalse($order->hasSheet());
        $this->assertSame(route('orders.show', $order), $order->sheetUrl());

        $this->actingAs($this->supplyDesk())
            ->get(route('inventory.requests'))
            ->assertOk()
            ->assertSee(route('orders.show', $order), false)
            ->assertDontSee(route('orders.package', $order), false);
    }

    public function test_an_order_with_a_sheet_points_at_the_package(): void
    {
        $order = $this->order();

        $this->assertTrue($order->hasSheet());
        $this->assertSame(route('orders.package', $order), $order->sheetUrl());
    }

    /* ---------------- and the link actually opens ---------------- */

    /**
     * A link the reader is refused is the same as no link. The package route
     * only narrows sales to their own orders, so the supply desk passes —
     * worth holding, because that is what makes this change safe.
     */
    public function test_the_supply_desk_can_open_the_package_it_is_sent_to(): void
    {
        $order = $this->order();

        $this->actingAs($this->supplyDesk())
            ->get(route('orders.package', $order))
            ->assertOk();
    }

    /* ---------------- without a query a row ---------------- */

    /**
     * Whether a sheet exists is asked once per row. Loading the sheet itself
     * to answer a yes/no would be a query and a record for every request on
     * the page, so the list asks withExists() instead.
     */
    public function test_the_list_costs_the_same_however_many_requests_are_on_it(): void
    {
        $desk = $this->supplyDesk();

        $load = function () use ($desk) {
            // Both runs start cold. The shelf and the alias pairs are read once
            // per request and held, so a warm second run would come out cheaper
            // for eight rows than for four and prove nothing either way.
            InventoryItem::forgetShelves();
            MaterialAlias::forget();

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($desk)->get(route('inventory.requests'))->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        foreach (range(1, 4) as $n) {
            $this->request($this->order());
        }

        $four = $load();

        foreach (range(1, 4) as $n) {
            $this->request($this->order());
        }

        $eight = $load();

        $this->assertSame($four, $eight,
            'the requests page asks the database per row: '.$four.' queries for four, '.$eight.' for eight');
    }
}
