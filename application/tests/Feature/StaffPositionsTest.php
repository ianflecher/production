<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Stations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The add-account form offers the positions the shop actually hires for.
 *
 * It used to list three buckets — Artist, Supply chain, Production — so hiring
 * a sewer meant choosing "Production", which hands them cutting, pairing and
 * quality control as well. The specific names were already what the app
 * matches on to decide which machines somebody sees; they just could not be
 * picked, so the only way to make a real sewer was to type the word into the
 * database by hand.
 */
class StaffPositionsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_SUPER_ADMIN, 'is_active' => true]);
    }

    public function test_the_form_offers_the_real_floor_positions(): void
    {
        $page = $this->actingAs($this->admin())->get('/users')->assertOk();

        foreach (['Sewing', 'Pairing', 'Laser Cutting', 'Quality Control',
            'Heat Press', 'Roller Press', 'Raw Materials', 'Printer', 'Embroidery'] as $position) {
            $page->assertSee($position);
        }
    }

    public function test_every_offered_position_is_one_the_app_understands(): void
    {
        // A position the station map has never heard of is an account that can
        // sign in and see nothing — the worst kind of working.
        $known = array_keys(Stations::stationsByRole());

        $unmatched = [];

        foreach (User::positionGroups() as $group => $positions) {
            foreach (array_keys($positions) as $value) {
                // Artist, inventory and mover are desks rather than stations;
                // they are recognised elsewhere.
                if (in_array($value, [User::JOB_ARTIST, 'inventory', 'mover'], true)) {
                    continue;
                }

                if (! in_array($value, $known, true)) {
                    $unmatched[] = $group.' / '.$value;
                }
            }
        }

        $this->assertSame([], $unmatched, 'these positions match no station rule');
    }

    public function test_hiring_a_sewer_gives_them_the_sewing_machines_only(): void
    {
        $this->actingAs($this->admin())->post('/users', [
            'name' => 'Nena Cruz', 'email' => 'nena@imprintcustoms.ph',
            'password' => 'imprint123', 'position' => 'sewing',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $sewer = User::where('email', 'nena@imprintcustoms.ph')->firstOrFail();
        $stations = Stations::forUser($sewer);

        $this->assertNotEmpty($stations);
        foreach ($stations as $key) {
            $this->assertStringStartsWith('sewing_', $key,
                'a sewer should not be handed the cutting and QC stations too');
        }
    }

    public function test_the_broad_roles_still_work_for_whoever_holds_them(): void
    {
        // Existing accounts hold these, and somebody who really does work the
        // whole line should still be able to say so.
        $this->actingAs($this->admin())->post('/users', [
            'name' => 'All Rounder', 'email' => 'all@imprintcustoms.ph',
            'password' => 'imprint123', 'position' => User::JOB_PRODUCTION,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $everyone = User::where('email', 'all@imprintcustoms.ph')->firstOrFail();

        $this->assertGreaterThan(
            count(Stations::forUser(User::factory()->create(['job_role' => 'sewing']))),
            count(Stations::forUser($everyone))
        );
    }

    public function test_a_leader_cannot_appoint_office_roles(): void
    {
        $leader = User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);

        $this->actingAs($leader)->post('/users', [
            'name' => 'Sneaky', 'email' => 'sneaky@imprintcustoms.ph',
            'password' => 'imprint123', 'position' => User::ROLE_SUPER_ADMIN,
        ])->assertInvalid(['position']);
    }

    public function test_a_leader_can_still_hire_the_floor(): void
    {
        $leader = User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);

        $this->actingAs($leader)->post('/users', [
            'name' => 'New Sewer', 'email' => 'sewer2@imprintcustoms.ph',
            'password' => 'imprint123', 'position' => 'sewing',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'sewer2@imprintcustoms.ph', 'job_role' => 'sewing']);
    }

    public function test_the_list_shows_the_position_they_were_hired_as(): void
    {
        $this->actingAs($this->admin())->post('/users', [
            'name' => 'Nena Cruz', 'email' => 'nena@imprintcustoms.ph',
            'password' => 'imprint123', 'position' => 'quality control',
        ]);

        $qc = User::where('email', 'nena@imprintcustoms.ph')->firstOrFail();

        $this->assertSame('Quality Control', $qc->positionLabel());
    }
}
