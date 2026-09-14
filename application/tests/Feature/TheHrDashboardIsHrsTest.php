<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\HrApplicant;
use App\Models\Inquiry;
use App\Models\InquiryDesign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The HR desk opens the dashboard and finds its own work on it.
 *
 * It used to find somebody else's. There was no HR branch, so the desk fell
 * through to the one written for the MOVER and was handed production order
 * counts and a button to the floor - "Following the floor: every job order and
 * where it has got to" - on the page of the person who hires people and has
 * nothing to do with a job order.
 *
 * Above that sat eight rows of the designing board, which is the one thing in
 * the shop the HR desk has no part in at any point: they do not draw, sell or
 * print.
 *
 * And beneath both, nothing of their own. The HR panel renders each block only
 * when it has something in it, so a desk with a clear day showed NO SIGN it
 * was the HR desk's page at all. A quiet day should say it is a quiet day.
 */
class TheHrDashboardIsHrsTest extends TestCase
{
    use RefreshDatabase;

    private function hr(): User
    {
        return User::factory()->create(['job_role' => User::JOB_HR, 'is_active' => true]);
    }

    /** A design moving today, so the board would have something to show. */
    private function aDesignInFlight(): void
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $inquiry = Inquiry::create([
            'client_id' => Client::create(['name' => 'Zandro', 'last_name' => 'Bernardo'])->id,
            'created_by' => $officer->id,
            'team' => $officer->team,
            'status' => Inquiry::STATUS_OPEN,
            'what_they_want' => 'Jersey',
        ]);

        $inquiry->designs()->create([
            'label' => 'JERSEY', 'position' => 0,
            'artist_id' => $artist->id,
            'status' => InquiryDesign::STATUS_WITH_ARTIST,
            'sent_at' => now(),
        ]);
    }

    public function test_the_hr_desk_is_not_handed_the_floors_work(): void
    {
        $page = $this->actingAs($this->hr())->get(route('dashboard'))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('Following the floor', $page,
            'the HR desk was given the mover\'s desk');
        $this->assertStringNotContainsString('Open production orders', $page,
            'and a button to a floor they have no part in');
    }

    public function test_the_designing_board_is_not_on_it(): void
    {
        $this->aDesignInFlight();

        $this->actingAs($this->hr())->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Designing board')
            ->assertDontSee('Zandro Bernardo');
    }

    /** And everybody else still has it — this narrows one desk, not the page. */
    public function test_everybody_else_still_has_the_designing_board(): void
    {
        $this->aDesignInFlight();

        foreach ([User::ROLE_SALES, User::JOB_ARTIST, User::ROLE_LEADER, User::JOB_PRODUCTION] as $role) {
            $this->actingAs(User::factory()->create(['job_role' => $role, 'is_active' => true]))
                ->get(route('dashboard'))
                ->assertOk()
                ->assertSee('Designing board');
        }
    }

    public function test_it_offers_the_hr_desk_its_own_way_in(): void
    {
        $this->actingAs($this->hr())->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Hiring')
            ->assertSee(route('hr.applicants.index'), false);
    }

    /**
     * A clear day says so, rather than showing an empty page that reads as
     * broken. This is the case the shop is actually in - every HR table is
     * empty - so it is the one that had to be got right.
     */
    public function test_a_quiet_desk_says_it_is_quiet(): void
    {
        $this->assertSame(0, HrApplicant::count());

        $this->actingAs($this->hr())->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Nothing is waiting on you today.');
    }

    /** And a busy one says what is waiting, and counts it. */
    public function test_a_busy_desk_says_what_is_waiting(): void
    {
        HrApplicant::create([
            'first_name' => 'Jomar', 'last_name' => 'Reyes',
            'contact_number' => '09171234567',
            'status' => HrApplicant::STATUS_NEW,
            'applied_at' => now(),
        ]);

        $this->actingAs($this->hr())->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Nothing is waiting on you today.')
            ->assertSee('1 thing waiting on you.')
            ->assertSee('Jomar');
    }

    /** The HR panel is not shown to people who cannot open HR. */
    public function test_the_hr_panel_belongs_to_the_hr_desk(): void
    {
        HrApplicant::create([
            'first_name' => 'Jomar', 'last_name' => 'Reyes',
            'contact_number' => '09171234567',
            'status' => HrApplicant::STATUS_NEW,
            'applied_at' => now(),
        ]);

        foreach ([User::ROLE_LEADER, 'supervisor', User::ROLE_SALES] as $role) {
            $this->actingAs(User::factory()->create(['job_role' => $role, 'is_active' => true]))
                ->get(route('dashboard'))
                ->assertOk()
                ->assertDontSee('Jomar');
        }
    }
}
