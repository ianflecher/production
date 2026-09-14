<?php

namespace Tests\Feature;

use App\Models\HrEmployee;
use App\Models\HrRequest;
use App\Models\User;
use App\Support\LeaveBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sick days and holidays are not the same allowance.
 *
 * The shop grants both, and sick_credits had been sitting in the table
 * meaning nothing: there was no way to file sick leave, so it went in as
 * plain leave and came off the VACATION balance. Somebody off with a fever
 * for three days lost three days of holiday for it, and the sick allowance
 * they were entitled to went unspent and uncounted.
 *
 * Worse than missing: the employment file printed "15 sick days allowed"
 * beside a number that nothing in the app could ever draw down.
 */
class BeingIllDoesNotSpendAHolidayTest extends TestCase
{
    use RefreshDatabase;

    private function hr(): User
    {
        return User::factory()->create(['job_role' => User::JOB_HR, 'is_active' => true]);
    }

    private function staff(?int $vacation = 10, ?int $sick = 5): HrEmployee
    {
        return HrEmployee::create([
            'user_id' => User::factory()->create(['job_role' => 'printer', 'is_active' => true])->id,
            'position' => 'Printer',
            'salary' => 18000,
            'salary_period' => 'monthly',
            'vacation_credits' => $vacation,
            'sick_credits' => $sick,
            'started_on' => now()->subYear(),
        ]);
    }

    private function file(HrEmployee $employee, string $type, string $from, string $to): HrRequest
    {
        $this->actingAs($employee->user)->post(route('hr.my.requests.store'), [
            'type' => $type,
            'starts_on' => $from,
            'ends_on' => $to,
            'reason' => 'Because.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        return HrRequest::latest('id')->firstOrFail();
    }

    private function approve(User $hr, HrRequest $request): void
    {
        $this->actingAs($hr)->post(route('hr.requests.decide', $request), [
            'status' => HrRequest::STATUS_APPROVED,
        ])->assertRedirect();
    }

    /* ---------------- the two are kept apart ---------------- */

    public function test_sick_leave_can_be_filed_at_all(): void
    {
        $employee = $this->staff();

        $request = $this->file($employee, HrRequest::TYPE_SICK, '2026-09-14', '2026-09-16');

        $this->assertSame(HrRequest::TYPE_SICK, $request->type);
        $this->assertSame(3.0, $request->working_days, 'sick leave was not counted in working days');
    }

    /** The whole point. */
    public function test_sick_leave_draws_the_sick_balance_and_not_the_holiday(): void
    {
        $hr = $this->hr();
        $employee = $this->staff(vacation: 10, sick: 5);

        $this->approve($hr, $this->file($employee, HrRequest::TYPE_SICK, '2026-09-14', '2026-09-16'));

        $employee->refresh();

        $this->assertSame(3.0, LeaveBalance::sick($employee)['taken']);
        $this->assertSame(2.0, LeaveBalance::sick($employee)['left']);

        $this->assertSame(0.0, LeaveBalance::for($employee)['taken'], 'being ill spent a holiday');
        $this->assertSame(10.0, LeaveBalance::for($employee)['left']);
    }

    /** And the other way round. */
    public function test_a_holiday_does_not_draw_the_sick_balance(): void
    {
        $hr = $this->hr();
        $employee = $this->staff(vacation: 10, sick: 5);

        $this->approve($hr, $this->file($employee, HrRequest::TYPE_LEAVE, '2026-09-14', '2026-09-16'));

        $employee->refresh();

        $this->assertSame(3.0, LeaveBalance::for($employee)['taken']);
        $this->assertSame(0.0, LeaveBalance::sick($employee)['taken']);
        $this->assertSame(5.0, LeaveBalance::sick($employee)['left']);
    }

    /** Each runs out on its own. */
    public function test_running_out_of_sick_days_leaves_the_holiday_intact(): void
    {
        $hr = $this->hr();
        $employee = $this->staff(vacation: 10, sick: 2);

        $this->approve($hr, $this->file($employee, HrRequest::TYPE_SICK, '2026-09-14', '2026-09-16'));

        $employee->refresh();

        $this->assertSame(0.0, LeaveBalance::sick($employee)['left']);
        $this->assertSame(10.0, LeaveBalance::for($employee)['left']);
    }

    /* ---------------- going over ---------------- */

    /** Said, not refused - and it says WHICH allowance was passed. */
    public function test_overdrawing_sick_leave_names_sick_leave(): void
    {
        $hr = $this->hr();
        $employee = $this->staff(vacation: 30, sick: 2);

        $request = $this->file($employee, HrRequest::TYPE_SICK, '2026-09-14', '2026-09-19');   // 6 days

        $this->assertTrue(LeaveBalance::wouldOverdraw($request));

        $this->actingAs($hr)->post(route('hr.requests.decide', $request), [
            'status' => HrRequest::STATUS_APPROVED,
        ])->assertRedirect()->assertSessionHas('success',
            fn ($said) => str_contains($said, 'sick leave'));
    }

    public function test_overdrawing_a_holiday_names_vacation_leave(): void
    {
        $hr = $this->hr();
        $employee = $this->staff(vacation: 2, sick: 30);

        $request = $this->file($employee, HrRequest::TYPE_LEAVE, '2026-09-14', '2026-09-19');

        $this->actingAs($hr)->post(route('hr.requests.decide', $request), [
            'status' => HrRequest::STATUS_APPROVED,
        ])->assertRedirect()->assertSessionHas('success',
            fn ($said) => str_contains($said, 'vacation leave'));
    }

    /**
     * A generous sick allowance must not excuse going over the holiday one.
     * The two balances are consulted separately or they are not separate.
     */
    public function test_a_full_sick_allowance_does_not_cover_a_spent_holiday(): void
    {
        $employee = $this->staff(vacation: 1, sick: 365);

        $request = $this->file($employee, HrRequest::TYPE_LEAVE, '2026-09-14', '2026-09-19');

        $this->assertTrue(LeaveBalance::wouldOverdraw($request),
            'the sick allowance was used to excuse overdrawing the holiday one');
    }

    /* ---------------- what draws on nothing ---------------- */

    /** Overtime and the rest still draw on neither allowance. */
    public function test_the_hourly_kinds_draw_on_neither(): void
    {
        $hr = $this->hr();
        $employee = $this->staff(vacation: 5, sick: 5);

        $request = $this->file($employee, HrRequest::TYPE_OVERTIME, '2026-09-14', '2026-09-16');
        $this->approve($hr, $request);

        $employee->refresh();

        $this->assertSame(5.0, LeaveBalance::for($employee)['left']);
        $this->assertSame(5.0, LeaveBalance::sick($employee)['left']);
        $this->assertFalse(LeaveBalance::wouldOverdraw($request->fresh()));
    }

    /** A change of schedule is a day at work, not a day off one. */
    public function test_a_change_of_schedule_draws_on_neither(): void
    {
        $hr = $this->hr();
        $employee = $this->staff(vacation: 5, sick: 5);

        $this->approve($hr, $this->file($employee, HrRequest::TYPE_SCHEDULE, '2026-09-14', '2026-09-16'));

        $employee->refresh();

        $this->assertSame(5.0, LeaveBalance::for($employee)['left']);
        $this->assertSame(5.0, LeaveBalance::sick($employee)['left']);
    }

    /* ---------------- an allowance nobody set ---------------- */

    /** Still not an allowance of none, and still per allowance. */
    public function test_a_sick_allowance_nobody_set_is_no_balance_at_all(): void
    {
        $employee = $this->staff(vacation: 10, sick: null);

        $this->assertNull(LeaveBalance::sick($employee));
        $this->assertNotNull(LeaveBalance::for($employee));

        $request = $this->file($employee, HrRequest::TYPE_SICK, '2026-09-14', '2026-09-19');

        $this->assertFalse(LeaveBalance::wouldOverdraw($request));
    }

    /* ---------------- what the pages say ---------------- */

    public function test_their_own_page_shows_both(): void
    {
        $employee = $this->staff(vacation: 12, sick: 7);

        $this->actingAs($employee->user)->get(route('hr.my'))
            ->assertOk()
            ->assertSee('My leave')
            ->assertSee('Vacation')
            ->assertSee('Sick')
            ->assertSeeInOrder(['>12<', 'allowed'], false)
            ->assertSeeInOrder(['>7<', 'allowed'], false);
    }

    /** And they can actually choose it when filing. */
    public function test_sick_leave_is_offered_on_the_filing_form(): void
    {
        $employee = $this->staff();

        $this->actingAs($employee->user)->get(route('hr.my'))
            ->assertOk()
            ->assertSee('Sick leave')
            ->assertSee('Vacation leave');
    }

    public function test_the_employment_file_shows_both(): void
    {
        $hr = $this->hr();
        $employee = $this->staff(vacation: 12, sick: 7);

        $this->actingAs($hr)->get(route('hr.employees.show', $employee))
            ->assertOk()
            ->assertSee('Vacation')
            ->assertSee('Sick');
    }

    /**
     * One set and the other not: the file should show the one that exists
     * rather than hiding both behind a single check.
     */
    public function test_the_file_shows_one_allowance_when_only_one_is_set(): void
    {
        $hr = $this->hr();
        $employee = $this->staff(vacation: null, sick: 7);

        $this->actingAs($hr)->get(route('hr.employees.show', $employee))
            ->assertOk()
            ->assertSee('Sick')
            ->assertSee('No allowance set');
    }
}
