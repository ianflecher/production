<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\User;
use App\Services\DataVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The fingerprint every open tab asks for on a timer, to know whether the page
 * it is showing has gone stale.
 *
 * Because every tab in the shop asks for it, its cost is multiplied by however
 * many people are logged in — so what it costs matters as much as what it says.
 */
class DataVersionTest extends TestCase
{
    use RefreshDatabase;

    private function order(string $number = 'IC2026-07700'): ProductionOrder
    {
        return ProductionOrder::create([
            'order_number' => $number,
            'customer_name' => 'Version Co',
            'product_type' => 'round_neck',
            'quantity' => 10,
            'due_date' => now()->addWeeks(2),
            'status' => 'active',
            'created_by' => User::factory()->create(['job_role' => User::ROLE_SALES])->id,
        ]);
    }

    public function test_it_costs_a_single_round_trip(): void
    {
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        DataVersion::current();

        // Asking table by table cost one query each, every few seconds, per
        // person. With a database on another machine that is the whole budget.
        $this->assertSame(1, $queries, 'the fingerprint should be one query, not one per table');
    }

    public function test_nothing_changing_means_the_page_stays_put(): void
    {
        $this->order();
        $before = DataVersion::current();

        $this->assertSame($before, DataVersion::current(), 'an idle system must not reload pages');
    }

    public function test_a_new_order_changes_it(): void
    {
        $before = DataVersion::current();
        $this->order();

        $this->assertNotSame($before, DataVersion::current());
    }

    public function test_a_step_moving_changes_it(): void
    {
        $order = $this->order();
        $task = $order->tasks()->create([
            'sequence' => 1, 'stage' => 1, 'department' => 'Layout',
            'status' => 'ready', 'approver_role' => 'leader',
        ]);

        $before = DataVersion::current();

        // updated_at is stored to the second, so a change has to land in a
        // later second than the last one to register. Screens check every 15
        // seconds, so in use there is always a gap; this makes it explicit.
        $this->travel(1)->second();
        $task->update(['status' => 'in_progress']);

        $this->assertNotSame($before, DataVersion::current(), 'the floor moving a job must reach open screens');
    }

    public function test_two_changes_inside_one_second_are_a_known_blind_spot(): void
    {
        $order = $this->order();
        $task = $order->tasks()->create([
            'sequence' => 1, 'stage' => 1, 'department' => 'Layout',
            'status' => 'ready', 'approver_role' => 'leader',
        ]);

        $before = DataVersion::current();
        $task->update(['status' => 'in_progress']);   // same second, no new rows

        // Documented, not desirable: the timestamp column has one-second
        // resolution, so this is invisible. It doesn't bite in practice because
        // screens check on a 15-second timer, never twice within a second.
        $this->assertSame($before, DataVersion::current());
    }

    public function test_a_deletion_changes_it_too(): void
    {
        $order = $this->order();
        $before = DataVersion::current();

        $order->delete();

        // Counts are part of the fingerprint, so a row going away registers
        // even though no updated_at moved forward.
        $this->assertNotSame($before, DataVersion::current());
    }

    public function test_stock_and_money_are_watched(): void
    {
        $order = $this->order();

        $before = DataVersion::current();
        $order->payments()->create([
            'amount' => 500, 'method' => 'Cash', 'kind' => 'downpayment',
            'paid_at' => now(), 'recorded_by' => $order->created_by,
        ]);
        $this->assertNotSame($before, DataVersion::current(), 'a payment should reach open screens');

        $before = DataVersion::current();
        \App\Models\InventoryItem::create([
            'name' => 'Cotton shirt White M', 'category' => 'COTTON SHIRT',
            'unit' => 'pc', 'quantity' => 10, 'beginning_stock' => 10,
        ]);
        $this->assertNotSame($before, DataVersion::current(), 'stock changes should reach open screens');
    }

    public function test_the_endpoint_answers_and_stays_cheap(): void
    {
        $user = User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $response = $this->actingAs($user)->get('/poll/version');

        $response->assertOk()->assertJsonStructure(['v']);

        // Session and user lookups come with any request; the point is that the
        // fingerprint itself has not gone back to a query per table.
        $this->assertLessThan(6, $queries, "the version check cost $queries queries");
    }

    public function test_the_notification_polling_endpoint_is_gone(): void
    {
        $user = User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);

        $this->actingAs($user)->get('/poll/notifications')->assertNotFound();
    }
}
