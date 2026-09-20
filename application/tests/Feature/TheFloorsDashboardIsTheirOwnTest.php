<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The designing board is not on the floor's dashboard.
 *
 * It was shared with everyone, on the reasoning that the spreadsheet it
 * replaced was read by everyone. That was the design side and the leaders
 * chasing them — not the roller press. Moi & Uno opened their dashboard to
 * eight rows of other people's drawings sitting above the five machines they
 * actually run.
 *
 * Their page is the station board. Asked with canSeeDesignBoard(), the same
 * question the sidebar link and the board page ask, so the three agree.
 */
class TheFloorsDashboardIsTheirOwnTest extends TestCase
{
    use RefreshDatabase;

    private function person(string $jobRole): User
    {
        return User::factory()->create(['job_role' => $jobRole, 'is_active' => true]);
    }

    private function dashboardOf(User $user): string
    {
        return $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();
    }

    /* ---------------- the floor ---------------- */

    public function test_a_press_operator_gets_no_design_board(): void
    {
        $html = $this->dashboardOf($this->person('roller press'));

        $this->assertStringNotContainsString('Designing board', $html);
        $this->assertStringNotContainsString('The latest designs and where each one has got to', $html);
    }

    public function test_no_station_role_gets_it(): void
    {
        foreach (['printer', 'sewing', 'quality control', 'pairing', 'laser cutting', 'small press'] as $role) {
            $this->assertStringNotContainsString(
                'The latest designs and where each one has got to',
                $this->dashboardOf($this->person($role)),
                $role.' still has the designing board on their dashboard'
            );
        }
    }

    /** Their own page still works, and still says what it always said. */
    public function test_the_floor_still_has_its_stations(): void
    {
        $this->assertStringContainsString('Your stations', $this->dashboardOf($this->person('roller press')));
    }

    /* ---------------- and the design side keeps it ---------------- */

    public function test_the_design_side_keeps_the_board(): void
    {
        foreach ([User::JOB_ARTIST, User::ROLE_SALES] as $role) {
            $this->assertStringContainsString(
                'The latest designs and where each one has got to',
                $this->dashboardOf($this->person($role)),
                $role.' lost the designing board'
            );
        }
    }

    public function test_leaders_keep_the_board(): void
    {
        $this->assertStringContainsString(
            'The latest designs and where each one has got to',
            $this->dashboardOf($this->person(User::ROLE_LEADER))
        );
    }
}
