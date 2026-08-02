<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: job_role is free text, but the permission role used to be derived
 * with a strict match(). A user seeded as "Finance" (capital F) silently fell
 * through to ROLE_AGENT — the finance desk got the station-operator UI and was
 * locked out of /finance. Roles must be case/whitespace insensitive.
 */
class JobRoleCaseTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $jobRole): User
    {
        return User::factory()->create(['job_role' => $jobRole, 'is_active' => true]);
    }

    public function test_capitalised_finance_is_still_the_finance_desk(): void
    {
        $u = $this->user('Finance');

        $this->assertSame(User::ROLE_FINANCE, $u->role);
        $this->assertTrue($u->isFinance());
        $this->assertTrue($u->canManageFinance());
        $this->assertFalse($u->isAgent(), 'finance must not be treated as a production agent');
        $this->assertFalse($u->canUseStations(), 'finance does not run machines');
    }

    public function test_capitalised_finance_can_reach_the_finance_pages(): void
    {
        $u = $this->user('Finance');

        $this->actingAs($u)->get('/finance')->assertOk();
        $this->actingAs($u)->get('/books')->assertOk();
        // …and must NOT get an agent's view.
        $this->actingAs($u)->get('/orders/create')->assertForbidden();
    }

    public function test_other_reserved_roles_are_also_case_insensitive(): void
    {
        $this->assertSame(User::ROLE_SALES, $this->user('Sales')->role);
        $this->assertSame(User::ROLE_LEADER, $this->user('Leader')->role);
        $this->assertSame(User::ROLE_SUPER_ADMIN, $this->user('Super_Admin')->role);
    }

    public function test_surrounding_whitespace_does_not_break_the_role(): void
    {
        $this->assertSame(User::ROLE_FINANCE, $this->user('  finance  ')->role);
    }

    public function test_a_normal_floor_role_is_still_an_agent(): void
    {
        $u = $this->user('Sewing');

        $this->assertSame(User::ROLE_AGENT, $u->role);
        $this->assertTrue($u->isAgent());
        $this->assertFalse($u->canManageFinance());
    }

    public function test_capitalised_artist_is_recognised(): void
    {
        $this->assertTrue($this->user('Artist')->isArtist());
    }
}
