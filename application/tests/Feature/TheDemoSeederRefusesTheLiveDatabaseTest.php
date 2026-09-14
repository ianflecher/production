<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The demo seeder refuses the live database.
 *
 * DemoDataSeeder truncates twenty-two tables - orders, clients, payments,
 * tasks, inventory, stock movements, messages, attendances - before it seeds
 * them, and nothing stopped it doing that to the shop's own database.
 *
 * It does not run on its own: DatabaseSeeder calls only the UserSeeder, so
 * `php artisan db:seed` is safe. But it is one command away -
 * `php artisan db:seed --class=DemoDataSeeder` - which is a thing somebody
 * types meaning to fill the sample, from the wrong folder. Two near-identical
 * checkouts sit side by side on that machine, so it is not a hypothetical.
 */
class TheDemoSeederRefusesTheLiveDatabaseTest extends TestCase
{
    use RefreshDatabase;

    /** Something of the shop's, to prove it survives. */
    private function somethingReal(): Client
    {
        return Client::create([
            'name' => 'Real',
            'last_name' => 'Client',
            'contact_number' => '09170000000',
            'created_by' => User::factory()->create(['job_role' => 'sales', 'is_active' => true])->id,
        ]);
    }

    /** Point the current connection at a database of the given name. */
    private function pretendDatabaseIs(string $name): void
    {
        config(['database.connections.'.config('database.default').'.database' => $name]);
    }

    public function test_it_refuses_the_live_database_by_name(): void
    {
        $client = $this->somethingReal();

        $this->pretendDatabaseIs('imprint_production');

        (new DemoDataSeeder)->run();

        $this->assertNotNull(Client::find($client->id),
            'the seeder wiped the live database');
        $this->assertSame(1, Client::count());
    }

    /** A differently-named copy that is still somebody's real shop. */
    public function test_it_refuses_anywhere_the_environment_is_production(): void
    {
        $client = $this->somethingReal();

        $this->pretendDatabaseIs('some_other_name');
        app()->detectEnvironment(fn () => 'production');

        (new DemoDataSeeder)->run();

        $this->assertNotNull(Client::find($client->id),
            'the seeder wiped a production database that is not named imprint_production');
        $this->assertSame(1, Client::count());
    }

    /**
     * And it still works where it is meant to, or the guard has simply
     * broken the seeder instead of making it safe.
     */
    public function test_it_still_runs_on_a_sandbox(): void
    {
        // The staff accounts it needs; it bails without them.
        User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        User::factory()->create(['job_role' => User::ROLE_FINANCE, 'is_active' => true]);
        User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);

        $this->pretendDatabaseIs('imprint_sample');

        (new DemoDataSeeder)->run();

        $this->assertGreaterThan(0, Client::count(),
            'the guard stopped the seeder working where it is supposed to');
    }
}
