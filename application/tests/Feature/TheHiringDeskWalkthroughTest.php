<?php

namespace Tests\Feature;

use App\Models\HrApplicant;
use App\Models\HrEmployee;
use App\Models\HrJobOffer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Somebody applies, is interviewed, is offered the job, and becomes staff.
 *
 * The HR desk is ten models and about thirty routes and had not one test on
 * it. Everything else in the shop is held down by something; this was the one
 * corner where a rename or a changed rule would be found out by the HR desk
 * rather than by the suite.
 *
 * It is also the corner where being wrong is worst. The path below ends by
 * CREATING A LOGIN: accepting an offer makes a user account with a temporary
 * password, and the same press writes the employment record that payslips and
 * loans hang off. A fault anywhere along it is a person who cannot be paid, or
 * an account nobody meant to exist.
 *
 * So this walks the whole way through rather than testing each press on its
 * own - the interesting faults in a sequence like this live in the joins.
 */
class TheHiringDeskWalkthroughTest extends TestCase
{
    use RefreshDatabase;

    private function hr(): User
    {
        return User::factory()->create(['job_role' => User::JOB_HR, 'is_active' => true]);
    }

    /** Somebody who has filled the form in from the street. */
    private function applied(array $extra = []): HrApplicant
    {
        $this->post(route('hr.apply.submit'), array_merge([
            'first_name' => 'Jomar',
            'last_name' => 'Reyes',
            'contact_number' => '09171234567',
            'email' => 'jomar@example.com',
            'position' => 'Printer',
            'about' => 'Two years on a DTF press.',
        ], $extra))->assertRedirect();

        return HrApplicant::latest('id')->firstOrFail();
    }

    /* ---------------- the door from the street ---------------- */

    public function test_anybody_can_apply_without_signing_in(): void
    {
        $this->assertGuest();

        $applicant = $this->applied();

        $this->assertSame('Jomar Reyes', $applicant->fullName());
        $this->assertSame(HrApplicant::STATUS_NEW, $applicant->status);
    }

    /** The form is the one thing here that is open to the world. */
    public function test_the_application_form_opens_for_a_stranger(): void
    {
        $this->get(route('hr.apply'))->assertOk();
        $this->get(route('hr.apply.thanks'))->assertOk();
    }

    public function test_an_application_without_a_name_is_refused(): void
    {
        $this->post(route('hr.apply.submit'), ['contact_number' => '0917'])
            ->assertSessionHasErrors(['first_name', 'last_name']);

        $this->assertSame(0, HrApplicant::count());
    }

    /* ---------------- and the door that is not ---------------- */

    /**
     * These pages are the employment file, not the hiring conversation: what
     * a person earns, what they have borrowed, what has been written down
     * about their conduct. The leaders and supervisors used to be let in on
     * the reasoning that hiring is everybody's business at that level. It is
     * not - a leader runs the work, and the file is the HR desk's.
     */
    public function test_only_the_hr_desk_and_the_owner_read_the_hr_pages(): void
    {
        $this->applied();

        $shutOut = [
            User::ROLE_LEADER, 'supervisor', 'sewing supervisor',
            User::ROLE_SALES, User::JOB_ARTIST, User::JOB_ARTIST_LEAD,
            User::ROLE_FINANCE, User::JOB_PRODUCTION, 'printer', 'sewing',
        ];

        foreach ($shutOut as $role) {
            $who = User::factory()->create(['job_role' => $role, 'is_active' => true]);

            foreach (['hr.applicants.index', 'hr.employees.index', 'hr.deadlines.index'] as $page) {
                $this->actingAs($who)->get(route($page))->assertForbidden();
            }
        }

        foreach ([User::JOB_HR, User::ROLE_SUPER_ADMIN] as $role) {
            $this->actingAs(User::factory()->create(['job_role' => $role, 'is_active' => true]))
                ->get(route('hr.applicants.index'))
                ->assertOk()
                ->assertSee('Jomar');
        }
    }

    /** And the door is not advertised to anybody who cannot open it. */
    public function test_the_hr_link_is_offered_only_to_those_two(): void
    {
        foreach ([User::ROLE_LEADER, 'supervisor', User::ROLE_SALES] as $role) {
            $this->actingAs(User::factory()->create(['job_role' => $role, 'is_active' => true]))
                ->get(route('dashboard'))
                ->assertOk()
                ->assertDontSee(route('hr.applicants.index'), false);
        }

        $this->actingAs(User::factory()->create(['job_role' => User::JOB_HR, 'is_active' => true]))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('hr.applicants.index'), false);
    }

    /**
     * Interviewing is a wider circle than reading the file. A supervisor
     * interviews the sewer who will work for them; that is not a reason to
     * show them anybody's payslip.
     */
    public function test_a_supervisor_can_still_be_named_as_the_interviewer(): void
    {
        $hr = $this->hr();
        $applicant = $this->applied();

        $supervisor = User::factory()->create([
            'job_role' => 'supervisor', 'name' => 'Sir Boying', 'is_active' => true,
        ]);

        $this->assertFalse($supervisor->canUseHr());
        $this->assertTrue($supervisor->canInterview());

        $this->actingAs($hr)->get(route('hr.applicants.show', $applicant))
            ->assertOk()
            ->assertSee('Sir Boying');

        $this->actingAs($hr)->post(route('hr.interviews.store', $applicant), [
            'interviewer_id' => $supervisor->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($supervisor->id, $applicant->fresh()->interviews()->latest('id')->first()->interviewer_id);
    }

    /* ---------------- the whole way through ---------------- */

    public function test_an_applicant_becomes_staff_with_a_login_and_a_record(): void
    {
        $hr = $this->hr();
        $applicant = $this->applied();

        // Shortlisted, then interviewed.
        $this->actingAs($hr)
            ->post(route('hr.applicants.status', $applicant), ['status' => HrApplicant::STATUS_SHORTLISTED])
            ->assertRedirect();

        $this->assertSame(HrApplicant::STATUS_SHORTLISTED, $applicant->fresh()->status);

        $this->actingAs($hr)->post(route('hr.interviews.store', $applicant), [
            'interviewer_id' => $hr->id,
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i'),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $interview = $applicant->fresh()->interviews()->latest('id')->first();
        $this->assertNotNull($interview, 'the interview was never written down');

        // Passed, so an offer can be made. Not before.
        $this->actingAs($hr)
            ->post(route('hr.applicants.status', $applicant), ['status' => HrApplicant::STATUS_PASSED])
            ->assertRedirect();

        $this->actingAs($hr)->post(route('hr.offers.store', $applicant))
            ->assertRedirect()->assertSessionHasNoErrors();

        $offer = HrJobOffer::latest('id')->firstOrFail();
        $this->assertSame(HrJobOffer::STATUS_DRAFT, $offer->status);

        $this->actingAs($hr)->post(route('hr.offers.send', $offer))->assertRedirect();
        $this->assertSame(HrJobOffer::STATUS_SENT, $offer->fresh()->status);

        // They say yes, and that is the press that makes a person staff.
        $this->actingAs($hr)->post(route('hr.offers.accept', $offer), [
            'email' => 'jomar@imprintcustoms.ph',
            'job_role' => 'Printer',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $account = User::where('email', 'jomar@imprintcustoms.ph')->first();

        $this->assertNotNull($account, 'saying yes did not create a login');
        $this->assertSame('Jomar Reyes', $account->name);
        $this->assertTrue($account->is_active);
        $this->assertTrue((bool) $account->must_change_password,
            'the temporary password would have become a permanent one');

        $employee = HrEmployee::where('user_id', $account->id)->first();
        $this->assertNotNull($employee, 'there is a login with no employment record behind it');
        $this->assertSame($applicant->id, $employee->hr_applicant_id);

        $this->assertSame(HrJobOffer::STATUS_ACCEPTED, $offer->fresh()->status);
        $this->assertSame(HrApplicant::STATUS_HIRED, $applicant->fresh()->status);
    }

    /** The password is shown once and never stored where it can be read. */
    public function test_the_temporary_password_is_kept_only_as_a_hash(): void
    {
        $hr = $this->hr();
        $offer = $this->offerReadyToAccept($hr);

        $response = $this->actingAs($hr)->post(route('hr.offers.accept', $offer), [
            'email' => 'once@imprintcustoms.ph',
            'job_role' => 'Printer',
        ]);

        $shown = session('newAccount');
        $this->assertIsArray($shown, 'the desk was never told the password to hand over');

        $account = User::where('email', 'once@imprintcustoms.ph')->firstOrFail();

        $this->assertNotSame($shown['password'], $account->password);
        $this->assertTrue(Hash::check($shown['password'], $account->password));
    }

    /** An address somebody already signs in with cannot be taken twice. */
    public function test_an_email_already_in_use_is_refused(): void
    {
        $hr = $this->hr();
        $taken = User::factory()->create(['email' => 'taken@imprintcustoms.ph', 'is_active' => true]);
        $offer = $this->offerReadyToAccept($hr);

        $this->actingAs($hr)->post(route('hr.offers.accept', $offer), [
            'email' => $taken->email,
            'job_role' => 'Printer',
        ])->assertSessionHasErrors('email');

        $this->assertSame(HrJobOffer::STATUS_SENT, $offer->fresh()->status,
            'the offer was marked accepted even though no account was made');
        $this->assertNull(HrEmployee::first(), 'an employment record was written with no login');
    }

    /** An offer cannot be made to somebody who has not passed. */
    public function test_no_offer_before_they_have_passed(): void
    {
        $hr = $this->hr();
        $applicant = $this->applied();

        $this->actingAs($hr)->post(route('hr.offers.store', $applicant))->assertForbidden();

        $this->assertSame(0, HrJobOffer::count());
    }

    /** And not twice over. */
    public function test_a_second_open_offer_is_refused(): void
    {
        $hr = $this->hr();
        $applicant = $this->applied();

        $this->actingAs($hr)->post(route('hr.applicants.status', $applicant),
            ['status' => HrApplicant::STATUS_PASSED]);

        $this->actingAs($hr)->post(route('hr.offers.store', $applicant))->assertRedirect();
        $this->actingAs($hr)->post(route('hr.offers.store', $applicant))->assertForbidden();

        $this->assertSame(1, HrJobOffer::count());
    }

    /** Turning it down sets them aside rather than leaving them mid-hire. */
    public function test_declining_an_offer_closes_it(): void
    {
        $hr = $this->hr();
        $offer = $this->offerReadyToAccept($hr);

        $this->actingAs($hr)->post(route('hr.offers.decline', $offer))->assertRedirect();

        $this->assertSame(HrJobOffer::STATUS_DECLINED, $offer->fresh()->status);
        $this->assertSame(HrApplicant::STATUS_SET_ASIDE, $offer->fresh()->applicant->status);
        $this->assertSame(0, User::where('email', 'jomar@example.com')->count());
    }

    /** An offer that is already answered cannot be answered again. */
    public function test_an_answered_offer_is_closed_to_a_second_answer(): void
    {
        $hr = $this->hr();
        $offer = $this->offerReadyToAccept($hr);

        $this->actingAs($hr)->post(route('hr.offers.decline', $offer))->assertRedirect();

        $this->actingAs($hr)->post(route('hr.offers.accept', $offer), [
            'email' => 'late@imprintcustoms.ph',
            'job_role' => 'Printer',
        ])->assertForbidden();

        $this->assertSame(0, User::where('email', 'late@imprintcustoms.ph')->count());
    }

    /** An offer sent, waiting on an answer. */
    private function offerReadyToAccept(User $hr): HrJobOffer
    {
        $applicant = $this->applied();

        $this->actingAs($hr)->post(route('hr.applicants.status', $applicant),
            ['status' => HrApplicant::STATUS_PASSED]);

        $this->actingAs($hr)->post(route('hr.offers.store', $applicant));

        $offer = HrJobOffer::latest('id')->firstOrFail();

        $this->actingAs($hr)->post(route('hr.offers.send', $offer));

        return $offer->fresh();
    }
}
