<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * How many queries each page costs.
 *
 * On the office PC the database is on localhost, so a query is ~0.1ms and the
 * count doesn't matter. Hosted elsewhere (Aiven) every query is a network
 * round-trip, so a page firing 150 queries takes seconds. This test measures
 * the counts so they can be driven down and kept down.
 */
class QueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    private function report(string $label, string $url, User $as): void
    {
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $response = $this->actingAs($as)->get($url);

        fwrite(STDERR, sprintf("  %-26s %-5s %4d queries\n", $label, $response->status(), $queries));
    }

    public function test_report_the_query_cost_of_each_page(): void
    {
        $this->seed(\Database\Seeders\UserSeeder::class);
        $this->seed(\Database\Seeders\DemoDataSeeder::class);

        $admin = User::all()->first(fn ($u) => $u->role === 'super_admin');
        $order = ProductionOrder::where('status', 'active')->latest('id')->first();

        fwrite(STDERR, "\nQueries per page (lower is better when the DB is remote):\n");

        $this->report('Dashboard', '/dashboard', $admin);
        $this->report('Orders list', '/orders', $admin);
        $this->report('Order detail', "/orders/{$order->id}", $admin);
        $this->report('New order form', '/orders/create', $admin);
        $this->report('Calendar', '/calendar', $admin);
        $this->report('Approvals', '/approvals', $admin);
        $this->report('Stations board', '/stations', $admin);
        $this->report('My tasks', '/my-tasks', $admin);
        $this->report('Inventory', '/inventory', $admin);
        $this->report('Products', '/products', $admin);
        $this->report('Bookkeeping', '/books', $admin);
        $this->report('Finance', '/finance', $admin);
        $this->report('Users', '/users', $admin);
        $this->report('Messages', '/messages', $admin);
        $this->report('Job order sheet', "/orders/{$order->id}/job-order", $admin);
        $this->report('Material requests', '/material-requests', $admin);

        $this->assertTrue(true, 'measurement only');
    }
}
