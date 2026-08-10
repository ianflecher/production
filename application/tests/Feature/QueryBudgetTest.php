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

        // [label, url, budget]
        $pages = [
            ['Dashboard', '/dashboard', 32],
            ['Orders list', '/orders', 20],
            ['Order detail', "/orders/{$order->id}", 32],
            ['New order form', '/orders/create', 18],
            ['Calendar', '/calendar', 20],
            ['Approvals', '/approvals', 22],
            ['Stations board', '/stations', 20],
            ['My tasks', '/my-tasks', 18],
            ['Inventory', '/inventory', 22],
            ['Products', '/products', 22],
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
