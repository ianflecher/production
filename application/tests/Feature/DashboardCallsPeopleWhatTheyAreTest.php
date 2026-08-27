<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dashboard greets somebody by the job they do.
 *
 * `role` is the ACCESS level — artists, the supply desk, the presses, sewing
 * and quality control are all "agent" in that column. The dashboard printed it
 * raw, so an artist was welcomed as AGENT while the header two inches away
 * said ARTIST. Nobody is called an agent in this shop.
 *
 * positionLabel() is the method that answers the question, and the header has
 * always used it. This is the dashboard being made to agree.
 */
class DashboardCallsPeopleWhatTheyAreTest extends TestCase
{
    use RefreshDatabase;

    private function badgeFor(string $jobRole): string
    {
        // `role` is derived from job_role, not stored — anything the app does
        // not recognise comes back as "agent", which is the whole reason the
        // badge said it.
        $user = User::factory()->create(['job_role' => $jobRole, 'is_active' => true]);

        return $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();
    }

    public function test_an_artist_is_called_an_artist(): void
    {
        $html = $this->badgeFor(User::JOB_ARTIST);

        $this->assertStringContainsString('Artist', $html);
        $this->assertStringNotContainsString('>AGENT<', $html);
        $this->assertStringNotContainsString('Agent</span>', $html);
    }

    public function test_the_artist_leader_keeps_his_own_title(): void
    {
        $this->assertStringContainsString('Artist Leader', $this->badgeFor(User::JOB_ARTIST_LEAD));
    }

    public function test_the_floor_is_called_by_its_bench(): void
    {
        // Hand-typed job roles, which is how the shop names most of its desks.
        foreach (['Sewing', 'Quality Control', 'Inventory'] as $bench) {
            $this->assertStringContainsString($bench, $this->badgeFor($bench));
        }
    }

    public function test_the_badge_agrees_with_the_header(): void
    {
        // The two sat inches apart and disagreed. They read from the same
        // method now, so there is nothing left to disagree about.
        $user = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $this->assertSame('agent', $user->role, 'the access level is still agent');
        $this->assertSame('Artist', $user->positionLabel(), 'but the person is an artist');

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee($user->positionLabel());
    }
}
