<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * How many queries each page costs, and a ceiling on it.
 *
 * On the office PC the database is on localhost, so a query is ~0.1ms and the
 * count doesn't matter. Hosted elsewhere every query is a network round-trip,
 * so a page firing 150 queries takes seconds.
 *
 * The counts are printed either way — they are useful to watch — but each page
 * also has a budget. The budgets sit a little above what the page costs today:
 * enough headroom that an honest extra query or two won't fail the build, tight
 * enough that a query per row cannot hide. That is the shape of the mistake
 * worth catching, because it looks fine on a laptop and only hurts in the shop.
 *
 * Raising a budget is a decision, not a formality. If a page needs more, say in
 * the commit why the work it does now is worth the round-trips.
 */
class QueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: int, 1: int} queries, status */
    private function measure(string $url, User $as): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $status = $this->actingAs($as)->get($url)->status();

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$queries, $status];
    }

    public function test_no_page_goes_over_its_query_budget(): void
    {
        $this->seed(\Database\Seeders\UserSeeder::class);
        $this->seed(\Database\Seeders\DemoDataSeeder::class);

        $admin = User::all()->first(fn ($u) => $u->role === 'super_admin');
        $order = ProductionOrder::where('status', 'active')->latest('id')->first();

        // The order form is step two: it is opened through the inquiry that
        // carries the client.
        $inquiry = \App\Models\Inquiry::firstOrCreate(
            ['client_id' => $order->client_id],
            ['created_by' => $admin->id, 'status' => \App\Models\Inquiry::STATUS_OPEN],
        );

        // [label, url, budget]
        $pages = [
['Dashboard', '/dashboard', 32],
            // 24: the list now loads the canonical client name (including the
            // surname used for sorting), workflow tasks and payment existence.
            // Those are page-wide eager loads, so the count stays flat as rows
            // are added rather than becoming one query per order.
            ['Orders list', '/orders', 24],
            ['Order detail', "/orders/{$order->id}", 32],
            ['New order form', '/orders/create?inquiry='.$inquiry->id, 18],
            ['Calendar', '/calendar', 20],
            // 27, not 22: the artists' bench is on this page now — every open
            // artist step with the order, the client and who has it, plus the
            // list of artists for the dropdown. Five loads, and five however
            // many steps are on it: the point of the budget is to catch a
            // query per ROW, and there isn't one.
            ['Approvals', '/approvals', 27],
            // 22, not 20: the running card names the client, and that name has
            // to come off the client record rather than the copy kept on the
            // order, which goes stale the moment the record is corrected. The
            // separate correctable floor sheet also needs its Tech Pack state.
            // Both are page-wide loads, flat however many stations run.
            ['Stations board', '/stations', 22],
            ['My tasks', '/my-tasks', 18],
            ['Inventory', '/inventory', 22],
            // 23: the page now also lists the orders waiting to be handed to
            // the client, with their payment state eager-loaded. Flat, however
            // many are queued.
            ['Products', '/products', 23],
            ['Bookkeeping', '/books', 20],
            ['Finance', '/finance', 24],
            ['Users', '/users', 20],
            ['Messages', '/messages', 26],
            ['Job order sheet', "/orders/{$order->id}/job-order", 26],
            ['Material requests', '/material-requests', 20],
        ];

        $over = [];

        fwrite(STDERR, "\nQueries per page (budget in brackets):\n");

        foreach ($pages as [$label, $url, $budget]) {
            [$queries, $status] = $this->measure($url, $admin);

            $flag = $queries > $budget ? '  <-- OVER' : '';
            fwrite(STDERR, sprintf("  %-26s %-5s %4d / %3d%s\n", $label, $status, $queries, $budget, $flag));

            $this->assertSame(200, $status, "{$label} ({$url}) did not return 200");

            if ($queries > $budget) {
                $over[] = "{$label} ({$url}): {$queries} queries, budget {$budget}";
            }
        }

        $this->assertSame([], $over, implode("\n", array_merge(
            ['These pages went over their query budget:'],
            $over,
            ['', 'A jump of roughly one query per row means something is being asked',
                'once per record that could be asked once for the page — check for a',
                'relation read inside a loop, and eager-load or withExists() it.'],
        )));
    }
}
