<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Supervisor is a position you can pick, not one you have to type.
 *
 * The role already existed and already carried leader-level access, but the
 * add-account form never offered it — so the only way to make one was to know
 * the exact word and get it into job_role some other way.
 */
class SupervisorPositionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_SUPER_ADMIN, 'is_active' => true]);
    }

    private function payload(array $o = []): array
    {
        return array_merge([
            'name' => 'Nova Supervisor',
            'email' => 'nova@imprintcustoms.ph',
            'password' => 'imprint123',
            'position' => User::JOB_SUPERVISOR,
        ], $o);
    }

    public function test_the_add_account_form_offers_supervisor(): void
    {
        $this->actingAs($this->admin())->get('/users')
            ->assertOk()
            // Matched on the value, not the exact markup: the options are
            // grouped now, so the tag carries more than it used to.
            ->assertSee('value="supervisor"', false)
            ->assertSee('Supervisor');
    }

    public function test_a_supervisor_account_can_be_created(): void
    {
        $this->actingAs($this->admin())->post('/users', $this->payload())
            ->assertRedirect()->assertSessionHasNoErrors();

        $made = User::where('email', 'nova@imprintcustoms.ph')->firstOrFail();

        $this->assertSame(User::JOB_SUPERVISOR, $made->job_role);
        $this->assertTrue($made->isSupervisor());
    }

    public function test_the_new_account_gets_the_access_a_supervisor_has(): void
    {
        $this->actingAs($this->admin())->post('/users', $this->payload());
        $made = User::where('email', 'nova@imprintcustoms.ph')->firstOrFail();

        // Same doors the existing supervisor already had.
        $this->assertTrue($made->isLeader());
        $this->actingAs($made)->get('/approvals')->assertOk();
        $this->actingAs($made)->get('/reports/bottlenecks')->assertOk();
    }

    public function test_the_list_calls_them_a_supervisor_not_a_leader(): void
    {
        $this->actingAs($this->admin())->post('/users', $this->payload());
        $made = User::where('email', 'nova@imprintcustoms.ph')->firstOrFail();

        // They count as a leader for access; the column should still say which
        // of the two they are, or nobody can tell them apart in the list.
        $this->assertSame('Supervisor', $made->positionLabel());
    }

    public function test_a_leader_cannot_appoint_one(): void
    {
        // Supervisor is leader-level access, so it sits with the other
        // leader-level positions: super admin only.
        $leader = User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);

        $this->actingAs($leader)->post('/users', $this->payload())
            ->assertInvalid(['position']);

        $this->assertDatabaseMissing('users', ['email' => 'nova@imprintcustoms.ph']);
    }
}
