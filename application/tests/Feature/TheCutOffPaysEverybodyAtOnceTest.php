<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\HrEmployee;
use App\Models\HrLoan;
use App\Models\HrPayslip;
use App\Models\User;
use App\Support\PayslipDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The cut-off: everybody's payslip for one period, in one go.
 *
 * Every payslip was typed one person at a time - period, period, gross, for
 * thirty-one people, twice a month. The figures had been worked out for a
 * while by then; what was missing was anything that would do the same work
 * for the whole shop at once.
 *
 * A run writes thirty-one people's wages and there is no undoing that
 * quietly, so it is previewed first, and anybody it cannot pay is listed with
 * the reason rather than quietly dropped.
 */
class TheCutOffPaysEverybodyAtOnceTest extends TestCase
{
    use RefreshDatabase;

    private function hr(): User
    {
        return User::factory()->create(['job_role' => User::JOB_HR, 'is_active' => true]);
    }

    private function staff(
        ?float $salary = 18000,
        string $period = 'monthly',
        ?string $started = '2024-01-01',
        ?string $ended = null,
    ): HrEmployee {
        return HrEmployee::create([
            'user_id' => User::factory()->create(['job_role' => 'printer', 'is_active' => true])->id,
            'position' => 'Printer',
            'salary' => $salary,
            'salary_period' => $period,
            'started_on' => $started,
            'ended_on' => $ended,
        ]);
    }

    private function cutOff(User $hr, string $from, string $to, bool $release = false)
    {
        return $this->actingAs($hr)->post(route('hr.payroll.run'), array_filter([
            'period_start' => $from,
            'period_end' => $to,
            'release' => $release ? 1 : null,
        ]));
    }

    /* ---------------- running it ---------------- */

    public function test_one_run_pays_everybody_on_the_books(): void
    {
        $hr = $this->hr();
        $a = $this->staff();
        $b = $this->staff();
        $c = $this->staff();

        $this->cutOff($hr, '2026-09-01', '2026-09-15')->assertRedirect();

        $this->assertSame(3, HrPayslip::count());

        foreach ([$a, $b, $c] as $e) {
            $this->assertSame(1, $e->payslips()->count());
        }
    }

    /** A fortnight of a monthly wage is half of it. */
    public function test_a_monthly_wage_is_shared_down_to_the_period(): void
    {
        $hr = $this->hr();
        $e = $this->staff(18000);

        $this->cutOff($hr, '2026-09-01', '2026-09-15');

        $this->assertSame('9000.00', (string) $e->payslips()->first()->gross);
    }

    /** A daily wage is the rate times the days the clock says they were there. */
    public function test_a_daily_wage_is_paid_for_the_days_actually_worked(): void
    {
        $hr = $this->hr();
        $e = $this->staff(600, 'daily');

        foreach (['2026-09-01', '2026-09-02', '2026-09-03'] as $day) {
            Attendance::create(['user_id' => $e->user_id, 'date' => $day, 'status' => 'present']);
        }

        $this->cutOff($hr, '2026-09-01', '2026-09-15');

        $this->assertSame('1800.00', (string) $e->payslips()->first()->gross);   // 600 x 3
    }

    /** The run says what it did, and what it came to. */
    public function test_the_run_reports_what_it_wrote(): void
    {
        $hr = $this->hr();
        $this->staff();
        $this->staff();

        $this->cutOff($hr, '2026-09-01', '2026-09-15')
            ->assertRedirect()
            ->assertSessionHas('success', fn ($said) => str_contains($said, '2 payslips recorded'));
    }

    /* ---------------- the same figures as a single payslip ---------------- */

    /**
     * The whole reason PayslipDraft exists. Two code paths producing a wage
     * is two chances to produce different ones from the same facts.
     */
    public function test_the_run_produces_what_recording_one_by_hand_produces(): void
    {
        $hr = $this->hr();
        $e = $this->staff(18000);

        Attendance::create([
            'user_id' => $e->user_id, 'date' => '2026-09-02', 'status' => 'present',
            'time_in' => '08:45:00', 'time_out' => '17:00:00', 'late_minutes' => 45,
        ]);

        HrLoan::create([
            'hr_employee_id' => $e->id, 'principal' => 5000, 'per_payslip' => 500,
            'reason' => 'Hospital bill', 'borrowed_on' => '2026-08-01', 'recorded_by' => $hr->id,
        ]);

        $draft = PayslipDraft::for($e, '2026-09-01', '2026-09-15');

        $this->cutOff($hr, '2026-09-01', '2026-09-15');

        $payslip = $e->payslips()->first();

        $this->assertSame($draft['gross'], (float) $payslip->gross);
        $this->assertSame($draft['net'], (float) $payslip->net);
        $this->assertEqualsCanonicalizing(
            array_column($draft['deductions'], 'label'),
            array_column($payslip->deductions, 'label'),
        );
    }

    /** And the loan balance moves, exactly as it does for a single payslip. */
    public function test_a_run_collects_the_loan_instalments(): void
    {
        $hr = $this->hr();
        $e = $this->staff();

        $loan = HrLoan::create([
            'hr_employee_id' => $e->id, 'principal' => 5000, 'per_payslip' => 500,
            'reason' => 'Hospital bill', 'borrowed_on' => '2026-08-01', 'recorded_by' => $hr->id,
        ]);

        $this->cutOff($hr, '2026-09-01', '2026-09-15');

        $this->assertSame(4500.0, $loan->fresh()->balance());
    }

    /* ---------------- who it will not pay ---------------- */

    /** Nobody is paid a salary nobody has set. */
    public function test_somebody_with_no_salary_is_left_out_and_said_so(): void
    {
        $hr = $this->hr();
        $paid = $this->staff(18000);
        $unpaid = $this->staff(null);

        $this->actingAs($hr)->get(route('hr.payroll.index', ['start' => '2026-09-01', 'end' => '2026-09-15']))
            ->assertOk()
            ->assertSee('Left out of this run')
            ->assertSee('No salary on their file yet.');

        $this->cutOff($hr, '2026-09-01', '2026-09-15');

        $this->assertSame(1, $paid->payslips()->count());
        $this->assertSame(0, $unpaid->payslips()->count());
    }

    /** Somebody who has left is not on this cut-off. */
    public function test_somebody_who_has_left_is_not_paid(): void
    {
        $hr = $this->hr();
        $gone = $this->staff(ended: '2026-08-31');

        $this->cutOff($hr, '2026-09-01', '2026-09-15');

        $this->assertSame(0, $gone->payslips()->count());
    }

    /** And somebody who had not started yet. */
    public function test_somebody_who_had_not_started_is_not_paid(): void
    {
        $hr = $this->hr();
        $future = $this->staff(started: '2026-10-01');

        $this->cutOff($hr, '2026-09-01', '2026-09-15');

        $this->assertSame(0, $future->payslips()->count());
    }

    /** Paid by the day, and there on none of them. */
    public function test_a_daily_worker_who_was_never_there_is_not_paid(): void
    {
        $hr = $this->hr();
        $e = $this->staff(600, 'daily');

        $this->cutOff($hr, '2026-09-01', '2026-09-15');

        $this->assertSame(0, $e->payslips()->count());
    }

    /**
     * The one that would actually cost money: running the same period twice
     * must not pay anybody twice.
     */
    public function test_running_the_same_period_twice_does_not_pay_twice(): void
    {
        $hr = $this->hr();
        $e = $this->staff();

        $this->cutOff($hr, '2026-09-01', '2026-09-15')->assertRedirect();
        $this->cutOff($hr, '2026-09-01', '2026-09-15')
            ->assertRedirect()
            ->assertSessionHas('success', fn ($said) => str_contains($said, 'Nothing to pay'));

        $this->assertSame(1, $e->payslips()->count());
    }

    /** A second, different period is a second payslip and not a duplicate. */
    public function test_the_next_cut_off_is_a_new_payslip(): void
    {
        $hr = $this->hr();
        $e = $this->staff();

        $this->cutOff($hr, '2026-09-01', '2026-09-15');
        $this->cutOff($hr, '2026-09-16', '2026-09-30');

        $this->assertSame(2, $e->payslips()->count());
    }

    /** And a loan is only collected once per cut-off, not once per attempt. */
    public function test_a_repeated_run_does_not_collect_the_loan_again(): void
    {
        $hr = $this->hr();
        $e = $this->staff();

        $loan = HrLoan::create([
            'hr_employee_id' => $e->id, 'principal' => 5000, 'per_payslip' => 500,
            'reason' => 'Hospital bill', 'borrowed_on' => '2026-08-01', 'recorded_by' => $hr->id,
        ]);

        $this->cutOff($hr, '2026-09-01', '2026-09-15');
        $this->cutOff($hr, '2026-09-01', '2026-09-15');

        $this->assertSame(4500.0, $loan->fresh()->balance());
    }

    /* ---------------- releasing ---------------- */

    /** Recorded is not released. Nobody sees a wage until the desk says so. */
    public function test_a_run_does_not_release_by_default(): void
    {
        $hr = $this->hr();
        $e = $this->staff();

        $this->cutOff($hr, '2026-09-01', '2026-09-15')
            ->assertSessionHas('success', fn ($said) => str_contains($said, 'Not released yet'));

        $this->assertFalse($e->payslips()->first()->isReleased());

        // And their own page shows them nothing - the wage exists in the
        // office and not yet to them.
        $this->actingAs($e->user)->get(route('hr.my'))
            ->assertOk()
            ->assertSee('No payslip has been released to you yet.');
    }

    public function test_a_run_can_release_as_it_goes(): void
    {
        $hr = $this->hr();
        $e = $this->staff();

        $this->cutOff($hr, '2026-09-01', '2026-09-15', release: true);

        $this->assertTrue($e->payslips()->first()->isReleased());
    }

    /** Or the whole period can be released afterwards, in one press. */
    public function test_a_whole_period_can_be_released_at_once(): void
    {
        $hr = $this->hr();
        $a = $this->staff();
        $b = $this->staff();

        $this->cutOff($hr, '2026-09-01', '2026-09-15');

        $this->actingAs($hr)->post(route('hr.payroll.release'), [
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-15',
        ])->assertRedirect()->assertSessionHas('success', fn ($s) => str_contains($s, '2 payslips released'));

        $this->assertTrue($a->payslips()->first()->isReleased());
        $this->assertTrue($b->payslips()->first()->isReleased());
    }

    /** Releasing does not reach into another period. */
    public function test_releasing_one_period_leaves_another_alone(): void
    {
        $hr = $this->hr();
        $e = $this->staff();

        $this->cutOff($hr, '2026-09-01', '2026-09-15');
        $this->cutOff($hr, '2026-09-16', '2026-09-30');

        $this->actingAs($hr)->post(route('hr.payroll.release'), [
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-15',
        ])->assertRedirect();

        $slips = $e->payslips()->get()->keyBy(fn ($p) => $p->period_start->toDateString());

        $this->assertTrue($slips['2026-09-01']->isReleased());
        $this->assertFalse($slips['2026-09-16']->isReleased());
    }

    /* ---------------- the preview ---------------- */

    /** Nothing is written by looking at it. */
    public function test_the_preview_writes_nothing(): void
    {
        $hr = $this->hr();
        $this->staff();

        $this->actingAs($hr)->get(route('hr.payroll.index', ['start' => '2026-09-01', 'end' => '2026-09-15']))
            ->assertOk()
            ->assertSee('to pay');

        $this->assertSame(0, HrPayslip::count());
    }

    public function test_the_preview_shows_the_net_each_person_would_get(): void
    {
        $hr = $this->hr();
        $this->staff(18000);

        $this->actingAs($hr)->get(route('hr.payroll.index', ['start' => '2026-09-01', 'end' => '2026-09-15']))
            ->assertOk()
            ->assertSee('₱9,000.00')        // gross, half a month
            ->assertSee('SSS');             // and the lines it would take off
    }

    /* ---------------- who may run it ---------------- */

    public function test_the_shop_floor_cannot_run_the_payroll(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff->user)->get(route('hr.payroll.index'))->assertForbidden();

        $this->actingAs($staff->user)->post(route('hr.payroll.run'), [
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-15',
        ])->assertForbidden();

        $this->assertSame(0, HrPayslip::count());
    }

    public function test_the_shop_floor_cannot_release_a_run(): void
    {
        $hr = $this->hr();
        $e = $this->staff();

        $this->cutOff($hr, '2026-09-01', '2026-09-15');

        $this->actingAs($e->user)->post(route('hr.payroll.release'), [
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-15',
        ])->assertForbidden();

        $this->assertFalse($e->payslips()->first()->isReleased());
    }
}
