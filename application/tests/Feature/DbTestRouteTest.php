<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The database-latency probe used when the app is hosted away from its data. */
class DbTestRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_is_not_open_to_the_internet(): void
    {
        // It reports where the database is and how it is reached.
        $this->get('/db-test')->assertRedirect('/login');
    }

    public function test_it_separates_connecting_from_querying(): void
    {
        $user = User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);

        $response = $this->actingAs($user)->get('/db-test');

        $response->assertOk()
            // Measuring them together was what made a slow connection read as
            // a slow database.
            ->assertSee('Opening a connection', false)
            ->assertSee('One query once open', false)
            ->assertSee('Average of ten queries', false);
    }

    public function test_any_signed_in_member_of_staff_can_run_it(): void
    {
        $agent = User::factory()->create(['job_role' => 'sewing', 'is_active' => true]);

        $this->actingAs($agent)->get('/db-test')->assertOk();
    }
}
