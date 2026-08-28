<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A finished job leaves the lists after sixty days and answers to its number.
 *
 * Completed work already had its own tab, but on a busy year that tab is most
 * of the list and none of it is anything anybody is doing. Sixty days after an
 * order is completed it is delivered, paid and settled: history, which is
 * something you look up rather than scroll past.
 *
 * Hidden, not deleted. Nothing is removed, no file is touched, and typing the
 * order number brings it straight back.
 */
class FinishedWorkLeavesTheListsTest extends TestCase
{
    use RefreshDatabase;

    private function officer(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
    }

    private function order(User $officer, string $number, string $status, ?\Carbon\CarbonInterface $completed = null): ProductionOrder
    {
        $order = ProductionOrder::create([
            'order_number' => $number, 'customer_name' => 'Longstanding Client',
            'product_type' => 'round_neck', 'quantity' => 10,
            'due_date' => now()->subDays(70), 'created_by' => $officer->id,
            'status' => $status, 'completed_at' => $completed,
        ]);

        return $order->refresh();
    }

    public function test_a_job_finished_long_ago_is_off_the_list(): void
    {
        $officer = $this->officer();
        $old = $this->order($officer, 'IC2026-OLD1', 'complete', now()->subDays(90));

        $this->actingAs($officer)->get('/orders?status=complete')
            ->assertOk()
            ->assertDontSee($old->order_number);
    }

    public function test_a_job_finished_recently_is_still_there(): void
    {
        // Sixty days, not "completed". Last week's work is still what people
        // are asking about.
        $officer = $this->officer();
        $recent = $this->order($officer, 'IC2026-NEW1', 'complete', now()->subDays(7));

        $this->actingAs($officer)->get('/orders?status=complete')
            ->assertOk()
            ->assertSee($recent->order_number);
    }

    public function test_its_number_still_finds_it(): void
    {
        $officer = $this->officer();
        $old = $this->order($officer, 'IC2026-OLD2', 'complete', now()->subDays(90));

        $this->actingAs($officer)->get('/orders?q=IC2026-OLD2')
            ->assertOk()
            ->assertSee($old->order_number);
    }

    public function test_a_client_name_does_not_drag_it_back(): void
    {
        // One long-standing customer would otherwise pull five years of
        // finished work up with them every time somebody looked them up.
        $officer = $this->officer();
        $old = $this->order($officer, 'IC2026-OLD3', 'complete', now()->subDays(90));
        $live = $this->order($officer, 'IC2026-LIVE', 'active');

        $this->actingAs($officer)->get('/orders?q=Longstanding')
            ->assertOk()
            ->assertSee($live->order_number)
            ->assertDontSee($old->order_number);
    }

    public function test_unfinished_work_never_leaves_however_old(): void
    {
        // Age is not the rule — being FINISHED is. A job stuck open for three
        // months is exactly the one somebody needs to see.
        $officer = $this->officer();
        $stale = $this->order($officer, 'IC2026-STUCK', 'active');
        \Illuminate\Support\Facades\DB::table('production_orders')
            ->where('id', $stale->id)->update(['updated_at' => now()->subDays(200)]);

        $this->actingAs($officer)->get('/orders')
            ->assertOk()
            ->assertSee($stale->order_number);
    }

    public function test_an_old_order_with_no_completed_date_still_goes(): void
    {
        // Orders finished before that column was filled in fall back to when
        // they were last touched, or the oldest jobs in the shop would be the
        // ones that never leave.
        $officer = $this->officer();
        $old = $this->order($officer, 'IC2026-NODATE', 'complete', null);
        // Written straight to the row: update() would stamp updated_at with
        // now(), which is the very column being set.
        \Illuminate\Support\Facades\DB::table('production_orders')
            ->where('id', $old->id)->update(['updated_at' => now()->subDays(120)]);

        $this->assertTrue($old->fresh()->isArchived());

        $this->actingAs($officer)->get('/orders?status=complete')
            ->assertOk()
            ->assertDontSee($old->order_number);
    }

    public function test_it_is_hidden_and_not_deleted(): void
    {
        $officer = $this->officer();
        $old = $this->order($officer, 'IC2026-OLD4', 'complete', now()->subDays(90));

        $this->assertDatabaseHas('production_orders', ['order_number' => 'IC2026-OLD4']);

        // And still opens, for anyone with the link.
        $this->actingAs($officer)->get(route('orders.show', $old))->assertOk();
    }
}
