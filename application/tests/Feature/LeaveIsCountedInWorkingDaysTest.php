<?php

namespace Tests\Feature;

use App\Models\Holiday;
use App\Models\HrEmployee;
use App\Models\HrRequest;
use App\Models\User;
use App\Support\LeaveBalance;
use App\Support\Workdays;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Leave costs working days, and a person has only so many.
 *
 * A request carried two dates and nothing counted what lay between them.
 * Friday to Monday is two days of leave and not four; over a holiday it may be
 * one. The desk worked that out on paper each time, and nothing on the request
 * said which answer they had used - so no balance could ever be trusted.
 *
 * And nobody had a balance at all. A person could file leave every week of the
 * year and the only thing between them and it was somebody remembering how
 * much they had already taken.
 *
 * Both come from the shop's own HRIS, which had them while this app did not.
 */
class LeaveIsCountedInWorkingDaysTest extends TestCase
{
    use RefreshDatabase;

    private function hr(): User
    {
        return User::factory()->create(['job_role' => User::JOB_HR, 'is_active' => true]);
    }

    private function staff(?int $vacation = 5): HrEmployee
    {
        return HrEmployee::create([
            'user_id' => User::factory()->create(['job_role' => 'printer', 'is_active' => true])->id,
            'position' => 'Printer',
            'salary' => 18000,
            'salary_period' => 'monthly',
            'vacation_credits' => $vacation,
            'started_on' => now()->subYear(),
        ]);
    }

    private function file(HrEmployee $employee, string $from, string $to, string $type = HrRequest::TYPE_LEAVE): HrRequest
    {
        $this->actingAs($employee->user)->post(route('hr.my.requests.store'), [
            'type' => $type,
            'starts_on' => $from,
            'ends_on' => $to,
            'reason' => 'Because.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        return HrRequest::latest('id')->firstOrFail();
    }

    /* ---------------- counting the days ---------------- */

    /**
     * The shop works six days and rests on Sunday. 2026-09-14 is a Monday, so
     * Monday to Saturday is six and the Sunday after it is not worked.
     */
    public function test_sunday_is_not_a_day_of_leave(): void
    {
        $this->assertSame(6, Workdays::between('2026-09-14', '2026-09-19'));   // Mon–Sat
        $this->assertSame(6, Workdays::between('2026-09-14', '2026-09-20'));   // + Sunday
        $this->assertSame(7, Workdays::between('2026-09-14', '2026-09-21'));   // + Monday
    }

    public function test_a_holiday_is_not_a_day_of_leave(): void
    {
        $this->assertSame(3, Workdays::between('2026-09-14', '2026-09-16'));

        Holiday::create(['date' => '2026-09-15', 'name' => 'A proclaimed day', 'kind' => Holiday::KIND_SPECIAL]);

        $this->assertSame(2, Workdays::between('2026-09-14', '2026-09-16'));
    }

    /** A single day is one day, not nothing. */
    public function test_one_day_is_one_day(): void
    {
        $this->assertSame(1, Workdays::between('2026-09-14', '2026-09-14'));
    }

    /** Backwards is a mistake, and a mistake should not credit anybody. */
    public function test_a_backwards_range_is_nothing(): void
    {
        $this->assertSame(0, Workdays::between('2026-09-20', '2026-09-14'));
    }

    /* ---------------- on the request itself ---------------- */

    public function test_filing_leave_writes_down_what_it_costs(): void
    {
        $employee = $this->staff();

        $request = $this->file($employee, '2026-09-14', '2026-09-20');   // Mon–Sun

        $this->assertSame(6.0, $request->working_days,
            'the request was kept without saying how many days it costs');
    }

    /** An arrangement about a working day is not days away from one. */
    public function test_overtime_carries_no_day_count(): void
    {
        $employee = $this->staff();

        $request = $this->file($employee, '2026-09-14', '2026-09-14', HrRequest::TYPE_OVERTIME);

        $this->assertNull($request->working_days);
    }

    /* ---------------- the balance ---------------- */

    public function test_approved_leave_draws_the_balance_down(): void
    {
        $hr = $this->hr();
        $employee = $this->staff(vacation: 5);

        $this->assertSame(5.0, LeaveBalance::for($employee)['left']);

        $request = $this->file($employee, '2026-09-14', '2026-09-16');   // 3 days

        // Waiting on an answer takes nothing yet.
        $this->assertSame(5.0, (float) LeaveBalance::for($employee->fresh())['left']);

        $this->actingAs($hr)->post(route('hr.requests.decide', $request), [
            'status' => HrRequest::STATUS_APPROVED,
        ])->assertRedirect();

        $balance = LeaveBalance::for($employee->fresh());

        $this->assertSame(3.0, $balance['taken']);
        $this->assertSame(2.0, $balance['left']);
    }

    /** A declined request costs nothing. */
    public function test_declined_leave_costs_nothing(): void
    {
        $hr = $this->hr();
        $employee = $this->staff(vacation: 5);
        $request = $this->file($employee, '2026-09-14', '2026-09-16');

        $this->actingAs($hr)->post(route('hr.requests.decide', $request), [
            'status' => HrRequest::STATUS_DECLINED,
        ])->assertRedirect();

        $this->assertSame(0.0, LeaveBalance::for($employee->fresh())['taken']);
    }

    /** Overtime never draws leave down, however much of it is approved. */
    public function test_overtime_does_not_eat_leave(): void
    {
        $hr = $this->hr();
        $employee = $this->staff(vacation: 5);
        $request = $this->file($employee, '2026-09-14', '2026-09-16', HrRequest::TYPE_OVERTIME);

        $this->actingAs($hr)->post(route('hr.requests.decide', $request), [
            'status' => HrRequest::STATUS_APPROVED,
        ])->assertRedirect();

        $this->assertSame(5.0, (float) LeaveBalance::for($employee->fresh())['left']);
    }

    /**
     * An allowance nobody has set is not an allowance of none. Staff who
     * predate the HR desk should not be told they have used up something
     * nobody ever gave them.
     */
    public function test_somebody_with_no_allowance_set_has_no_balance_to_be_over(): void
    {
        $employee = $this->staff(vacation: null);

        $this->assertNull(LeaveBalance::for($employee));

        $request = $this->file($employee, '2026-09-14', '2026-09-19');

        $this->assertFalse(LeaveBalance::wouldOverdraw($request));
    }

    /**
     * Said, not refused. Going over is a decision the desk is allowed to make
     * — somebody with none left may still be let off for a funeral — but it
     * should not be made without being told.
     */
    public function test_approving_past_the_allowance_says_so(): void
    {
        $hr = $this->hr();
        $employee = $this->staff(vacation: 2);
        $request = $this->file($employee, '2026-09-14', '2026-09-19');   // 6 days

        $this->assertTrue(LeaveBalance::wouldOverdraw($request));

        $this->actingAs($hr)->post(route('hr.requests.decide', $request), [
            'status' => HrRequest::STATUS_APPROVED,
        ])->assertRedirect()->assertSessionHas('success',
            fn ($said) => str_contains($said, 'more leave than they had left'));

        // Approved all the same. The desk was told, not stopped.
        $this->assertSame(HrRequest::STATUS_APPROVED, $request->fresh()->status);
    }

    /** And within the allowance it says nothing extra. */
    public function test_approving_within_the_allowance_is_quiet(): void
    {
        $hr = $this->hr();
        $employee = $this->staff(vacation: 20);
        $request = $this->file($employee, '2026-09-14', '2026-09-16');

        $this->actingAs($hr)->post(route('hr.requests.decide', $request), [
            'status' => HrRequest::STATUS_APPROVED,
        ])->assertRedirect()->assertSessionHas('success',
            fn ($said) => ! str_contains($said, 'more leave than'));
    }

    /** The person can see what they have left. */
    public function test_the_balance_is_on_their_own_page(): void
    {
        $employee = $this->staff(vacation: 7);

        $this->actingAs($employee->user)->get(route('hr.my'))
            ->assertOk()
            ->assertSee('My leave')
            ->assertSee('7');
    }

    /** Counted off what was granted, not recounted from the dates. */
    public function test_a_later_holiday_does_not_change_what_was_already_granted(): void
    {
        $hr = $this->hr();
        $employee = $this->staff(vacation: 10);
        $request = $this->file($employee, '2026-09-14', '2026-09-16');   // 3 days

        $this->actingAs($hr)->post(route('hr.requests.decide', $request), [
            'status' => HrRequest::STATUS_APPROVED,
        ])->assertRedirect();

        $this->assertSame(3.0, LeaveBalance::for($employee->fresh())['taken']);

        // The palace proclaims a day in the middle of leave already granted.
        Holiday::create(['date' => '2026-09-15', 'name' => 'Proclaimed later', 'kind' => Holiday::KIND_SPECIAL]);

        $this->assertSame(3.0, LeaveBalance::for($employee->fresh())['taken'],
            'a holiday declared afterwards quietly changed what somebody had already been granted');
    }
}
