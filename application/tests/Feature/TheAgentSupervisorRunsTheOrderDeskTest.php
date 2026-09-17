<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The account officers' supervisor supervises the account officers.
 *
 * supervisorScope() has carried this docblock since it was written:
 *
 *     design      → the account officers and artists (agent → artist)
 *     production  → the floor from printing through QC (printer → QC)
 *
 * and "design" was unreachable. Every supervisor came out as "production"
 * whatever they actually ran, and the one path to "design" was a line matching
 * a single person by name. So Maam Ann, who runs the order desk, was told she
 * supervised the printers: her Users page listed the floor, and the sidebar
 * gave her the Station board and no way to the orders.
 *
 * The orders page itself always admitted her. Only the link was missing.
 */
class TheAgentSupervisorRunsTheOrderDeskTest extends TestCase
{
    use RefreshDatabase;

    private function supervisorOfAgents(): User
    {
        return User::factory()->create([
            'name' => 'Maam Ann', 'job_role' => User::JOB_AGENT_SUPERVISOR, 'is_active' => true,
        ]);
    }

    private function supervisorOfTheFloor(): User
    {
        return User::factory()->create([
            'name' => 'Sir Boying', 'job_role' => User::JOB_SUPERVISOR, 'is_active' => true,
        ]);
    }

    /** The sidebar as it is actually drawn for this person. */
    private function sidebarHas(User $user, string $href): bool
    {
        $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

        return (bool) preg_match('#<a href="[^"]*'.preg_quote($href, '#').'" class="nav-item#', $html);
    }

    /* ---------------- she is a supervisor, of a different slice ---------------- */

    public function test_she_reads_as_a_supervisor(): void
    {
        $ann = $this->supervisorOfAgents();

        $this->assertTrue($ann->isSupervisor());
        // The role: middleware trusts this value for every leader page.
        $this->assertSame(User::ROLE_LEADER, $ann->role);
        $this->assertTrue($ann->isLeader());
    }

    public function test_her_slice_is_the_order_desk_not_the_floor(): void
    {
        $this->assertSame('design', $this->supervisorOfAgents()->supervisorScope());
        $this->assertSame('production', $this->supervisorOfTheFloor()->supervisorScope());
    }

    /* ---------------- who she oversees ---------------- */

    public function test_she_oversees_the_account_officers_and_artists(): void
    {
        $ann = $this->supervisorOfAgents();

        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $this->assertTrue($ann->oversees($officer));
        $this->assertTrue($ann->oversees($artist));
    }

    /** And not the printers, which is what she was being given before. */
    public function test_she_does_not_oversee_the_production_floor(): void
    {
        $ann = $this->supervisorOfAgents();

        foreach (['printer', 'sewing', 'quality control', 'pairing'] as $role) {
            $worker = User::factory()->create(['job_role' => $role, 'is_active' => true]);
            $this->assertFalse($ann->oversees($worker), $role.' is not hers to supervise');
        }
    }

    /** The floor supervisor is untouched by any of this. */
    public function test_the_floor_supervisor_still_oversees_the_floor(): void
    {
        $boying = $this->supervisorOfTheFloor();

        $printer = User::factory()->create(['job_role' => 'printer', 'is_active' => true]);
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $this->assertTrue($boying->oversees($printer));
        $this->assertFalse($boying->oversees($officer));
    }

    /* ---------------- the link she was missing ---------------- */

    public function test_the_orders_page_was_already_hers(): void
    {
        $this->actingAs($this->supervisorOfAgents())
            ->get(route('orders.index'))
            ->assertOk();
    }

    public function test_the_sidebar_now_takes_her_there(): void
    {
        $this->assertTrue($this->sidebarHas($this->supervisorOfAgents(), '/orders'),
            'she runs the order desk and the sidebar had no way to it');
    }

    /** The floor supervisor's sidebar is unchanged — the stations are his. */
    public function test_the_floor_supervisor_gets_no_orders_link(): void
    {
        $this->assertFalse($this->sidebarHas($this->supervisorOfTheFloor(), '/orders'));
    }

    /* ---------------- and the name hack is gone ---------------- */

    /**
     * managementScope() matched the string "carla" in a person's name to hand
     * out the design scope. Maam Carla is a leader, and leaders already get
     * that scope from the branch below it, so the line decided nothing — but
     * it would have followed any new hire who happened to be called Carla.
     */
    public function test_a_persons_name_no_longer_decides_what_they_supervise(): void
    {
        $carla = User::factory()->create([
            'name' => 'Maam Carla', 'job_role' => User::ROLE_LEADER, 'is_active' => true,
        ]);

        // Unchanged for the real one: she is a leader, and leaders run design.
        $this->assertSame('design', $carla->managementScope());

        // But the name on its own buys nothing now.
        $namedCarla = User::factory()->create([
            'name' => 'Carla Mendoza', 'job_role' => 'printer', 'is_active' => true,
        ]);

        $this->assertNotSame('design', $namedCarla->managementScope());
    }
}
