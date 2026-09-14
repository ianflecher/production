<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The front door asks which you are.
 *
 * Two different people arrive at this address wanting opposite things:
 * somebody who works here wants to sign in, and somebody who does not wants
 * to apply. The apply form has been public all along and there was no way to
 * find it - the root redirected to the dashboard, which bounced a guest to
 * the login form, which says "Authorized Imprint Customs staff only" and
 * offers nothing else. An applicant had to be sent the URL by hand.
 *
 * Somebody already signed in is not asked. They wanted the dashboard.
 */
class TheFrontDoorAsksWhichYouAreTest extends TestCase
{
    use RefreshDatabase;

    /* ---------------- the choice ---------------- */

    public function test_a_guest_at_the_front_door_is_asked_which_they_are(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Which are you?')
            ->assertSee('I already work here')
            ->assertSee("I'm applying for a job", false);
    }

    /** Both doors are real links, not decoration. */
    public function test_both_doors_lead_somewhere(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee(route('login'), false)
            ->assertSee(route('hr.apply'), false);
    }

    public function test_the_staff_door_opens_the_sign_in_page(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('Welcome back');
    }

    /** And the applicant's door needs no account, which is the whole point. */
    public function test_the_applicant_door_opens_without_signing_in(): void
    {
        $this->get(route('hr.apply'))->assertOk();
    }

    /* ---------------- signed in already ---------------- */

    /**
     * Somebody who works here and is already signed in should not be asked a
     * question they answered months ago.
     */
    public function test_somebody_signed_in_goes_straight_to_their_dashboard(): void
    {
        $user = User::factory()->create(['job_role' => 'printer', 'is_active' => true]);

        $this->actingAs($user)->get('/')->assertRedirect(route('dashboard'));
    }

    /* ---------------- neither page is a dead end ---------------- */

    /**
     * Somebody who picked wrong must not have to go back and start again -
     * most people will arrive at one of these directly from a link anyway.
     */
    public function test_the_sign_in_page_says_where_to_apply(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Apply for a job')
            ->assertSee(route('hr.apply'), false);
    }

    public function test_the_application_form_says_where_to_sign_in(): void
    {
        $this->get(route('hr.apply'))
            ->assertOk()
            ->assertSee('Already work here?')
            ->assertSee(route('login'), false);
    }

    /* ---------------- it opens nothing it should not ---------------- */

    /**
     * A door that asks a question is still a door. Choosing "I already work
     * here" must not be a way past the login.
     */
    public function test_the_front_door_lets_nobody_in_by_itself(): void
    {
        $this->get('/')->assertOk();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    /** And it says nothing about the shop to somebody not signed in. */
    public function test_the_front_door_gives_nothing_away(): void
    {
        $staff = User::factory()->create([
            'name' => 'Somebody Who Works Here',
            'job_role' => 'printer',
            'is_active' => true,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertDontSee($staff->name)
            ->assertDontSee($staff->email);
    }
}
