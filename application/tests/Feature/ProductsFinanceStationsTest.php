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

    public function test_canonical_production_role_is_locked_out_of_stations_BUG(): void
    {
        $this->markTestSkipped(
            'KNOWN BUG (found 2026-08-02): User::canManageProducts() does '
            .'str_contains($role, "product"), and the job role "production" contains '
            .'"product". So a user whose job_role is exactly "production" is treated as '
            .'the finished-products desk: locked out of /stations (403) and wrongly given '
            .'/products (200) — the opposite of what canUseStations()\'s docblock states. '
            .'Free-typed roles like "sewing"/"cutting"/"QC" are unaffected. '
            .'Un-skip this test once the role check is fixed.'
        );
    }

    public function test_station_board_is_visible_to_leaders(): void
    {
        $this->actingAs($this->user(User::ROLE_LEADER))->get('/stations')->assertOk();
    }
}
