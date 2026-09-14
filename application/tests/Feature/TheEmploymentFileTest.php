<?php

namespace Tests\Feature;

use App\Models\HrEmployee;
use App\Models\HrIncident;
use App\Models\HrLoan;
use App\Models\HrPayslip;
use App\Models\HrRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Payslips, loans, incidents and the requests staff file about their own time.
 *
 * This is the half of HR where being wrong costs money or costs somebody their
 * privacy, and it had no tests at all. Three things are worth holding down
 * more than the rest:
 *
 *   a payslip is not the employee's to see until it is RELEASED - a draft is
 *   the desk still working out the figures, and showing it would have people
 *   asking about a number nobody has agreed yet;
 *
 *   one employee must never see another's anything - the page is "my HR", and
 *   a wrong id in the address has to be refused rather than answered;
 *
 *   and a decision already made is not up for a second answer, in either
 *   direction: the desk cannot re-decide a settled request, and the person
 *   cannot withdraw one after it has been answered.
 */
class TheEmploymentFileTest extends TestCase
{
    use RefreshDatabase;

    private function hr(): User
    {
        return User::factory()->create(['job_role' => User::JOB_HR, 'is_active' => true]);
    }

    /** A member of staff with an employment record behind their login. */
    private function staff(string $name = 'Jomar Reyes'): HrEmployee
    {
        $user = User::factory()->create([
            'name' => $name, 'job_role' => 'printer', 'is_active' => true,
        ]);

        return HrEmployee::create([
            'user_id' => $user->id,
            'position' => 'Printer',
            'salary' => 18000,
            'salary_period' => 'monthly',
            'started_on' => now()->subYear(),
        ]);
    }

    /* ---------------- payslips ---------------- */

    public function test_a_payslip_is_not_theirs_to_see_until_it_is_released(): void
    {
        $hr = $this->hr();
        $employee = $this->staff();

        $this->actingAs($hr)->post(route('hr.payslips.store', $employee), [
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-15',
            'gross' => 9000,
            'note' => 'ZZFIRSTHALF',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $payslip = HrPayslip::latest('id')->firstOrFail();
        $this->assertFalse($payslip->isReleased(), 'a payslip was released the moment it was written');

        // The desk can see it. The person it is about cannot, yet.
        $this->actingAs($employee->user)->get(route('hr.my'))
            ->assertOk()
            ->assertSee('No payslip has been released to you yet.');

        $this->actingAs($hr)->post(route('hr.payslips.release', $payslip))->assertRedirect();

        $this->assertTrue($payslip->fresh()->isReleased());

        $this->actingAs($employee->user)->get(route('hr.my'))
            ->assertOk()
            ->assertDontSee('No payslip has been released to you yet.');
    }

    /** Releasing twice does not move the date somebody was told about. */
    public function test_releasing_an_already_released_payslip_changes_nothing(): void
    {
        $hr = $this->hr();
        $employee = $this->staff();

        $this->actingAs($hr)->post(route('hr.payslips.store', $employee), [
            'period_start' => '2026-09-01', 'period_end' => '2026-09-15', 'gross' => 9000,
        ]);

        $payslip = HrPayslip::latest('id')->firstOrFail();

        $this->actingAs($hr)->post(route('hr.payslips.release', $payslip))->assertRedirect();
        $first = $payslip->fresh()->released_at;

        $this->travel(2)->minutes();
        $this->actingAs($hr)->post(route('hr.payslips.release', $payslip))->assertRedirect();

        $this->assertEquals($first, $payslip->fresh()->released_at,
            'a second press moved the date the payslip says it was released on');
    }

    public function test_a_period_that_ends_before_it_starts_is_refused(): void
    {
        $hr = $this->hr();
        $employee = $this->staff();

        $this->actingAs($hr)->post(route('hr.payslips.store', $employee), [
            'period_start' => '2026-09-15',
            'period_end' => '2026-09-01',
            'gross' => 9000,
        ])->assertSessionHasErrors('period_end');

        $this->assertSame(0, HrPayslip::count());
    }

    /* ---------------- loans ---------------- */

    public function test_a_loan_is_written_down_and_paid_off_in_parts(): void
    {
        $hr = $this->hr();
        $employee = $this->staff();

        $this->actingAs($hr)->post(route('hr.loans.store', $employee), [
            'principal' => 5000,
            'borrowed_on' => now()->subMonth()->toDateString(),
            'reason' => 'Tuition',
            'per_payslip' => 500,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $loan = HrLoan::latest('id')->firstOrFail();
        $this->assertSame($employee->id, $loan->hr_employee_id);

        $this->actingAs($hr)->post(route('hr.loans.payments.store', $loan), [
            'amount' => 1500,
            'paid_on' => now()->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, $loan->fresh()->payments()->count());
        $this->assertEquals(1500, $loan->fresh()->payments()->sum('amount'));
    }

    public function test_a_loan_of_nothing_is_refused(): void
    {
        $hr = $this->hr();
        $employee = $this->staff();

        $this->actingAs($hr)->post(route('hr.loans.store', $employee), [
            'principal' => 0,
            'borrowed_on' => now()->toDateString(),
        ])->assertSessionHasErrors('principal');

        $this->assertSame(0, HrLoan::count());
    }

    /* ---------------- incidents ---------------- */

    public function test_an_incident_is_written_down_and_the_person_is_told(): void
    {
        $hr = $this->hr();
        $employee = $this->staff();

        $this->actingAs($hr)->post(route('hr.incidents.store', $employee), [
            'occurred_on' => now()->toDateString(),
            'kind' => 'tardiness',
            'description' => 'ZZLATE three times this week.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $incident = HrIncident::latest('id')->firstOrFail();

        $this->actingAs($employee->user)->get(route('hr.my'))
            ->assertOk()
            ->assertSee('ZZLATE three times this week.');

        // And they can say they have read it.
        $this->actingAs($employee->user)
            ->post(route('hr.my.incidents.read', $incident))
            ->assertRedirect();

        $this->assertNotNull($incident->fresh()->acknowledged_at,
            'the person read it and nothing recorded that they had');
    }

    public function test_an_incident_of_an_unknown_kind_is_refused(): void
    {
        $hr = $this->hr();
        $employee = $this->staff();

        $this->actingAs($hr)->post(route('hr.incidents.store', $employee), [
            'occurred_on' => now()->toDateString(),
            'kind' => 'something_invented',
            'description' => 'nope',
        ])->assertSessionHasErrors('kind');

        $this->assertSame(0, HrIncident::count());
    }

    /* ---------------- requests ---------------- */

    public function test_staff_file_a_request_and_the_desk_answers_it(): void
    {
        $hr = $this->hr();
        $employee = $this->staff();

        $this->actingAs($employee->user)->post(route('hr.my.requests.store'), [
            'type' => HrRequest::TYPE_LEAVE,
            'starts_on' => now()->addWeek()->toDateString(),
            'ends_on' => now()->addWeek()->addDay()->toDateString(),
            'reason' => 'ZZFAMILY matter',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $req = HrRequest::latest('id')->firstOrFail();
        $this->assertTrue($req->isPending());

        $this->actingAs($hr)->post(route('hr.requests.decide', $req), [
            'status' => HrRequest::STATUS_APPROVED,
            'decision_note' => 'Fine.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(HrRequest::STATUS_APPROVED, $req->fresh()->status);
        $this->assertNotNull($req->fresh()->decided_at);
    }

    /** Answered once. A settled request is not up for a second answer. */
    public function test_a_decided_request_cannot_be_decided_again(): void
    {
        $hr = $this->hr();
        $req = $this->pendingRequest();

        $this->actingAs($hr)->post(route('hr.requests.decide', $req), [
            'status' => HrRequest::STATUS_APPROVED,
        ])->assertRedirect();

        $this->actingAs($hr)->post(route('hr.requests.decide', $req), [
            'status' => HrRequest::STATUS_DECLINED,
        ])->assertForbidden();

        $this->assertSame(HrRequest::STATUS_APPROVED, $req->fresh()->status);
    }

    /** And it cannot be withdrawn once it has been answered. */
    public function test_an_answered_request_cannot_be_withdrawn(): void
    {
        $hr = $this->hr();
        $req = $this->pendingRequest();
        $owner = $req->employee->user;

        $this->actingAs($hr)->post(route('hr.requests.decide', $req), [
            'status' => HrRequest::STATUS_DECLINED,
        ])->assertRedirect();

        $this->actingAs($owner)->post(route('hr.my.requests.withdraw', $req))->assertForbidden();

        $this->assertSame(HrRequest::STATUS_DECLINED, $req->fresh()->status);
    }

    /* ---------------- one person, one file ---------------- */

    /**
     * The page is called "my HR". Somebody else's id in the address is the
     * only way to ask for another person's file, and it has to be refused
     * rather than answered.
     */
    public function test_one_persons_request_is_not_another_persons_to_withdraw(): void
    {
        $mine = $this->pendingRequest();
        $stranger = $this->staff('Somebody Else');

        $this->actingAs($stranger->user)
            ->post(route('hr.my.requests.withdraw', $mine))
            ->assertForbidden();

        $this->assertTrue($mine->fresh()->isPending());
    }

    public function test_one_persons_incident_is_not_another_persons_to_acknowledge(): void
    {
        $hr = $this->hr();
        $employee = $this->staff();
        $stranger = $this->staff('Somebody Else');

        $this->actingAs($hr)->post(route('hr.incidents.store', $employee), [
            'occurred_on' => now()->toDateString(),
            'kind' => 'note',
            'description' => 'Private to this person.',
        ]);

        $incident = HrIncident::latest('id')->firstOrFail();

        $this->actingAs($stranger->user)
            ->post(route('hr.my.incidents.read', $incident))
            ->assertForbidden();

        $this->assertNull($incident->fresh()->acknowledged_at);
    }

    /**
     * And a stranger's payslip is not on my page.
     *
     * Told apart by the GROSS, not by the note: the page shows the period, the
     * gross, the deductions and the net, and nothing else. A marker put in the
     * note would have been invisible either way, and this test would have
     * passed whether or not the page leaked - which is worse than not having
     * it, because it reads like the question was asked.
     */
    public function test_my_page_shows_only_my_own(): void
    {
        $hr = $this->hr();
        $me = $this->staff('Mine Own');
        $them = $this->staff('Somebody Else');

        foreach ([[$me, 9111], [$them, 7222]] as [$employee, $gross]) {
            $this->actingAs($hr)->post(route('hr.payslips.store', $employee), [
                'period_start' => '2026-09-01', 'period_end' => '2026-09-15',
                'gross' => $gross,
            ]);

            $this->actingAs($hr)->post(route('hr.payslips.release', HrPayslip::latest('id')->firstOrFail()));
        }

        $this->actingAs($me->user)->get(route('hr.my'))
            ->assertOk()
            ->assertSee('9,111.00')       // proves the page shows a payslip at all
            ->assertDontSee('7,222.00');  // and only mine
    }

    /** Staff who predate the HR desk have no file, and are told so plainly. */
    public function test_somebody_with_no_employment_record_is_told_rather_than_shown_a_blank(): void
    {
        $old = User::factory()->create(['job_role' => 'printer', 'is_active' => true]);

        $this->assertNull(HrEmployee::forUser($old));

        $this->actingAs($old)->get(route('hr.my'))
            ->assertOk()
            ->assertSee('HR has not set up your employee record');
    }

    /* ---------------- the desk's own diary ---------------- */

    /**
     * The dates the HR desk itself has to hit - a payslip run, a government
     * filing. Ticked off and untickable again, because a thing marked done by
     * mistake has to be recoverable without somebody re-typing it.
     */
    public function test_a_deadline_is_listed_ticked_off_and_put_back(): void
    {
        $hr = $this->hr();

        $this->actingAs($hr)->post(route('hr.deadlines.store'), [
            'label' => 'ZZSSS remittance',
            'kind' => 'government',
            'due_on' => now()->addWeek()->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $deadline = \App\Models\HrDeadline::latest('id')->firstOrFail();

        $this->actingAs($hr)->get(route('hr.deadlines.index'))
            ->assertOk()->assertSee('ZZSSS remittance');

        $this->assertFalse($deadline->isDone());

        $this->actingAs($hr)->post(route('hr.deadlines.toggle', $deadline))->assertRedirect();
        $this->assertTrue($deadline->fresh()->isDone());

        $this->actingAs($hr)->post(route('hr.deadlines.toggle', $deadline))->assertRedirect();
        $this->assertFalse($deadline->fresh()->isDone(), 'a deadline ticked by mistake could not be put back');

        $this->actingAs($hr)->post(route('hr.deadlines.destroy', $deadline))->assertRedirect();
        $this->assertSame(0, \App\Models\HrDeadline::count());
    }

    /** A kind nobody has heard of is refused. */
    public function test_a_deadline_of_an_unknown_kind_is_refused(): void
    {
        $this->actingAs($this->hr())->post(route('hr.deadlines.store'), [
            'label' => 'Something',
            'kind' => 'invented',
            'due_on' => now()->toDateString(),
        ])->assertSessionHasErrors('kind');

        $this->assertSame(0, \App\Models\HrDeadline::count());
    }

    /** The desk's diary is the desk's - not the floor's, and not a leader's. */
    public function test_the_diary_belongs_to_the_hr_desk(): void
    {
        foreach ([User::ROLE_LEADER, 'supervisor', User::ROLE_SALES, 'printer'] as $role) {
            $this->actingAs(User::factory()->create(['job_role' => $role, 'is_active' => true]))
                ->post(route('hr.deadlines.store'), [
                    'label' => 'Not mine to add',
                    'kind' => 'payslip',
                    'due_on' => now()->toDateString(),
                ])->assertForbidden();
        }

        $this->assertSame(0, \App\Models\HrDeadline::count());
    }

    private function pendingRequest(): HrRequest
    {
        $employee = $this->staff();

        $this->actingAs($employee->user)->post(route('hr.my.requests.store'), [
            'type' => HrRequest::TYPE_LEAVE,
            'starts_on' => now()->addWeek()->toDateString(),
            'reason' => 'Because.',
        ]);

        return HrRequest::latest('id')->firstOrFail();
    }
}
