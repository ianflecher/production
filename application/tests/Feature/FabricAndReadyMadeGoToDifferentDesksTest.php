<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\InventoryItem;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two shelves, two keepers, two queues.
 *
 * The raw materials supervisor holds the fabric, by the kilo. The raw
 * materials desk holds the ready-made stock — the caps, the boxes, the tapes —
 * by the piece. One queue for both meant each of them reading past the other's
 * work to find their own, and a sidebar badge counting requests they could do
 * nothing about.
 *
 * Which shelf is not something the system can guess from a name: of the
 * materials asked for on the live floor, "QA700" matches nothing in either
 * list and "COTTON HOODIE" matches the ready-made one. So the officer says, on
 * the job order, and the request carries the answer.
 */
class FabricAndReadyMadeGoToDifferentDesksTest extends TestCase
{
    use RefreshDatabase;

    private function sales(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
    }

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
    private function orderWithBoth(User $sales): ProductionOrder
    {
        $this->actingAs($sales)->post('/orders', [
            'order_number' => 'IC2026-09090',
            'client_name' => 'Two', 'client_last_name' => 'Shelves',
            'client_contact' => '0917-000-0000', 'client_address' => 'Angeles City',
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10],
        ]);

        $order = ProductionOrder::where('order_number', 'IC2026-09090')->firstOrFail();

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

    /* ---------------- the officer says which ---------------- */

    /** The choice is on the job order form, per material line. */
    public function test_the_form_offers_the_choice_on_each_line(): void
    {
        $sales = $this->sales();
        $order = $this->orderWithBoth($sales);

        $this->actingAs($sales)->get(route('job-orders.production', $order))
            ->assertOk()
            ->assertSee('raw_material_kind[]', false)
            ->assertSee('Ready-made')
            // The prose wraps, so this is the longest fragment that stays whole.
            ->assertSee('to the raw materials desk', false);
    }

    /** And what they pick is what the job order holds. */
    public function test_the_choice_is_saved_against_the_material(): void
    {
        $order = $this->orderWithBoth($this->sales());
        $jo = $order->jobOrder->fresh();

        $this->assertSame(InventoryItem::KIND_FABRIC, $jo->rawMaterialKind('AIRCOOL 11X1 WHT'));
        $this->assertSame(InventoryItem::KIND_READY_MADE, $jo->rawMaterialKind('CAP'));
    }

    /** A material nobody classified is fabric — it has to land somewhere. */
    public function test_an_unanswered_material_is_fabric(): void
    {
        $order = $this->orderWithBoth($this->sales());

        $this->assertSame(InventoryItem::KIND_FABRIC,
            $order->jobOrder->fresh()->rawMaterialKind('SOMETHING NOBODY PICKED'));
    }

    /* ---------------- the requests split ---------------- */

    public function test_each_request_carries_its_shelf(): void
    {
        $order = $this->orderWithBoth($this->sales());

        $this->assertSame(InventoryItem::KIND_FABRIC,
            $order->materialRequests()->where('material', 'AIRCOOL 11X1 WHT')->value('kind'));
        $this->assertSame(InventoryItem::KIND_READY_MADE,
            $order->materialRequests()->where('material', 'CAP')->value('kind'));
    }

    /** Each keeper sees their own work and not the other's. */
    public function test_each_desk_sees_only_its_own_queue(): void
    {
        $supervisor = $this->supervisor();
        $desk = $this->desk();
        $this->orderWithBoth($this->sales());

        $hers = $this->actingAs($supervisor)->get(route('inventory.requests'))->assertOk()->getContent();
        $this->assertStringContainsString('AIRCOOL 11X1 WHT', $hers);
        $this->assertStringNotContainsString('>CAP<', $hers);

        $theirs = $this->actingAs($desk)->get(route('inventory.requests'))->assertOk()->getContent();
        $this->assertStringContainsString('CAP', $theirs);
        $this->assertStringNotContainsString('AIRCOOL 11X1 WHT', $theirs);
    }

    /** And is told about their own, separately. */
    public function test_each_keeper_is_told_about_their_own(): void
    {
        $supervisor = $this->supervisor();
        $desk = $this->desk();

        $this->orderWithBoth($this->sales());

        $told = fn ($user) => AppNotification::pendingFor($user)
            ->filter(fn ($n) => str_contains((string) $n->title, 'material request'))
            ->implode('body', ' ');

        $this->assertStringContainsString('fabric', $told($supervisor));
        $this->assertStringContainsString('ready-made', $told($desk));
    }

    /* ---------------- and cannot take the other's ---------------- */

    public function test_a_desk_cannot_issue_against_the_other_shelf(): void
    {
        $desk = $this->desk();
        $supervisor = $this->supervisor();
        $order = $this->orderWithBoth($this->sales());

        $fabric = $order->materialRequests()->where('kind', InventoryItem::KIND_FABRIC)->firstOrFail();
        $readyMade = $order->materialRequests()->where('kind', InventoryItem::KIND_READY_MADE)->firstOrFail();

        $this->actingAs($desk)
            ->post(route('inventory.requests.reject', $fabric), ['operator_name' => 'Desk'])
            ->assertForbidden();

        $this->actingAs($supervisor)
            ->post(route('inventory.requests.reject', $readyMade), ['operator_name' => 'Khaye'])
            ->assertForbidden();
    }

    /* ---------------- the badge counts your own ---------------- */

    public function test_the_badge_counts_each_keepers_own_queue(): void
    {
        $supervisor = $this->supervisor();
        $desk = $this->desk();
        $this->orderWithBoth($this->sales());

        $pill = fn ($user) => preg_match(
            '#Material Requests\s*<span class="count-pill">(\d+)</span>#',
            $this->actingAs($user)->get(route('inventory.index'))->getContent(),
            $m
        ) ? (int) $m[1] : 0;

        // One fabric line and one ready-made line, one size each.
        $this->assertSame(1, $pill($supervisor));
        $this->assertSame(1, $pill($desk));
    }

    /** Changing your mind while it is still waiting moves it. */
    public function test_reclassifying_a_material_moves_its_request(): void
    {
        $order = $this->orderWithBoth($this->sales());

        $order->jobOrder->update(['raw_material_kinds' => [
            'AIRCOOL 11X1 WHT' => InventoryItem::KIND_READY_MADE,
            'CAP' => InventoryItem::KIND_READY_MADE,
        ]]);

        $order->refresh()->syncMaterialRequests();

        $this->assertSame(InventoryItem::KIND_READY_MADE,
            $order->materialRequests()->where('material', 'AIRCOOL 11X1 WHT')->value('kind'));
    }
}
