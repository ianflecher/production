<?php

namespace Tests\Feature;

use App\Models\ProductItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The remaining desks: finished-products inventory, finance, and the station
 * board — access rules plus their main state change.
 */
class ProductsFinanceStationsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $jobRole): User
    {
        return User::factory()->create(['job_role' => $jobRole, 'is_active' => true]);
    }

    // ---- Finished products -------------------------------------------------

    public function test_products_desk_can_add_stock(): void
    {
        // canManageProducts() matches any job role containing "inventory"/"product".
        $desk = $this->user('inventory');

        $this->actingAs($desk)->post('/products', [
            'name' => 'Finished Tee',
            'unit' => 'pcs',
            'quantity' => 40,
            'operator_name' => 'Maria',
        ])->assertRedirect();

        $this->assertDatabaseHas('product_items', ['name' => 'Finished Tee', 'unit' => 'pcs']);
        $this->assertEqualsWithDelta(40.0, (float) ProductItem::where('name', 'Finished Tee')->value('quantity'), 0.01);
    }

    public function test_products_requires_core_fields(): void
    {
        $this->actingAs($this->user('inventory'))->post('/products', [])
            ->assertInvalid(['name', 'unit', 'quantity', 'operator_name']);
    }

    public function test_artist_cannot_access_products_inventory(): void
    {
        $this->actingAs($this->user(User::JOB_ARTIST))->get('/products')->assertForbidden();
    }

    public function test_supply_chain_cannot_access_products_inventory(): void
    {
        // Raw materials and finished products are separate desks.
        $this->actingAs($this->user(User::JOB_SUPPLY_CHAIN))->get('/products')->assertForbidden();
    }

    // ---- Finance -----------------------------------------------------------

    public function test_finance_user_can_open_finance_and_export(): void
    {
        $finance = $this->user(User::ROLE_FINANCE);

        $this->actingAs($finance)->get('/finance')->assertOk();
        $this->actingAs($finance)->get('/finance/export')->assertOk();
    }

    public function test_agent_cannot_see_finance(): void
    {
        $this->actingAs($this->user(User::JOB_PRODUCTION))->get('/finance')->assertForbidden();
        $this->actingAs($this->user(User::JOB_PRODUCTION))->get('/finance/export')->assertForbidden();
    }

    // ---- Station board -----------------------------------------------------

    public function test_floor_staff_can_open_the_station_board(): void
    {
        // Free-typed floor roles (how staff are actually set up) reach the board.
        foreach (['sewing', 'cutting', 'QC'] as $role) {
            $this->actingAs($this->user($role))->get('/stations')->assertOk();
        }
    }

    public function test_starting_a_station_requires_operator_and_job(): void
    {
        $this->actingAs($this->user('sewing'))
            ->post('/stations/start', [])
            ->assertInvalid(['station', 'operator_name', 'production_order_id']);
    }

    /**
     * Regression: "production" contains the substring "product", which used to
     * make canManageProducts() true — locking the factory floor out of the
     * station board and handing them the finished-products desk. Fixed 2026-08-02.
     */
    public function test_production_team_gets_stations_not_the_products_desk(): void
    {
        $prod = $this->user(User::JOB_PRODUCTION);

        $this->assertFalse($prod->canManageProducts(), '"production" must not read as the products desk');
        $this->assertTrue($prod->canUseStations(), 'production staff run machines — they need the station board');

        $this->actingAs($prod)->get('/stations')->assertOk();
        $this->actingAs($prod)->get('/products')->assertForbidden();
    }

    /** A role that genuinely names the products desk still works. */
    public function test_production_inventory_role_still_reaches_products(): void
    {
        $desk = $this->user('Production Inventory');

        $this->assertTrue($desk->canManageProducts());
        $this->actingAs($desk)->get('/products')->assertOk();
    }

    public function test_leader_does_not_get_the_machine_station_board(): void
    {
        $this->actingAs($this->user(User::ROLE_LEADER))->get('/stations')->assertForbidden();
    }

    public function test_supervisor_boundaries_only_include_their_line_stations(): void
    {
        $line = $this->user(User::JOB_SUPERVISOR);
        $sewing = $this->user(User::JOB_SEWING_SUPERVISOR);

        $lineStations = \App\Services\Stations::forUser($line);
        $sewingStations = \App\Services\Stations::forUser($sewing);

        $this->assertNotEmpty($lineStations);
        $this->assertContains('printer_dtf_printer', $lineStations);
        $this->assertContains('laser_cutting_1', $lineStations);
        $this->assertContains('sewing_1', $lineStations);
        $this->assertContains('qc_1', $lineStations);
        $this->assertNotContains('raw_materials', $lineStations);
        $this->assertNotContains('sticker', $lineStations);
        $this->assertTrue(collect($sewingStations)->every(fn ($station) => str_starts_with($station, 'sewing_')));
        $this->actingAs($line)->get('/stations')->assertOk();
        $this->actingAs($sewing)->get('/stations')->assertOk();
    }

    public function test_parallel_station_areas_have_at_least_five_benches(): void
    {
        $keys = array_keys(\App\Services\Stations::all());

        foreach (['small_press_', 'roller_press_', 'laser_cutting_', 'manual_cutting_', 'pairing_', 'sewing_', 'qc_'] as $prefix) {
            $this->assertGreaterThanOrEqual(
                5,
                count(array_filter($keys, fn ($key) => str_starts_with($key, $prefix))),
                $prefix.' must have at least five stations',
            );
        }
    }

    public function test_supervisors_do_not_get_the_finance_tab_or_route(): void
    {
        $supervisor = $this->user(User::JOB_SUPERVISOR);

        $this->actingAs($supervisor)->get('/dashboard')
            ->assertOk()
            ->assertDontSee(route('finance.index'), false);
        $this->actingAs($supervisor)->get('/finance')->assertForbidden();
    }
}
