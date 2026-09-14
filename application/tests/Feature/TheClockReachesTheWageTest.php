<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\HrEmployee;
use App\Models\HrPayslip;
use App\Models\HrRequest;
use App\Models\User;
use App\Support\AttendancePay;
use App\Support\Wage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The clock reaches the wage.
 *
 * Attendance recorded late, undertime and overtime minutes and the payslip
 * read none of them. Somebody 419 minutes late over a fortnight was paid the
 * same as somebody never late, and approved overtime was never paid at all.
 * Numbers that visibly do not matter are how people learn to stop pressing
 * the button.
 *
 * Overtime is paid for what was BOTH approved and worked. Paying whatever the
 * clock says would pay anybody who chose to stay late; paying whatever was
 * approved would pay for overtime nobody did.
 */
class TheClockReachesTheWageTest extends TestCase
{
    use RefreshDatabase;

    private function hr(): User
    {
        return User::factory()->create(['job_role' => User::JOB_HR, 'is_active' => true]);
    }

    /** ₱18,000 a month → ₱690.10 a day → ₱86.26 an hour. */
    private function staff(float $salary = 18000, string $period = 'monthly'): HrEmployee
    {
        return HrEmployee::create([
            'user_id' => User::factory()->create(['job_role' => 'printer', 'is_active' => true])->id,
            'position' => 'Printer',
            'salary' => $salary,
            'salary_period' => $period,
            'started_on' => '2024-01-01',
        ]);
    }

    private function clocked(HrEmployee $e, string $day, int $late = 0, int $under = 0, int $over = 0): void
    {
        Attendance::create([
            'user_id' => $e->user_id,
            'date' => $day,
            'status' => 'present',
            'time_in' => '08:00:00',
            'time_out' => '17:00:00',
            'late_minutes' => $late,
            'undertime_minutes' => $under,
            'overtime_minutes' => $over,
        ]);
    }

    private function approvedOvertime(HrEmployee $e, string $day, string $from, string $to): HrRequest
    {
        return $e->requests()->create([
            'type' => HrRequest::TYPE_OVERTIME,
            'starts_on' => $day,
            'starts_at' => $from,
            'ends_at' => $to,
            'reason' => 'Rush job.',
            'status' => HrRequest::STATUS_APPROVED,
        ]);
    }

    /* ---------------- what an hour is worth ---------------- */

    public function test_a_monthly_salary_becomes_a_daily_and_hourly_rate(): void
    {
        $e = $this->staff(18000);

        $this->assertSame(18000.0, Wage::monthly($e));
        $this->assertSame(690.1, Wage::daily($e));      // 18000 * 12 / 313
        $this->assertSame(86.26, Wage::hourly($e));     // / 8
    }

    /** A per-cut-off wage is half a month, and a daily wage is not a monthly one. */
    public function test_the_other_pay_periods_are_converted_not_taken_raw(): void
    {
        $this->assertSame(18000.0, Wage::monthly($this->staff(9000, 'semi_monthly')));

        $daily = $this->staff(600, 'daily');
        $this->assertSame(600.0, Wage::daily($daily));
        $this->assertSame(15650.0, Wage::monthly($daily));   // 600 * 313 / 12
    }

    public function test_overtime_carries_a_quarter_on_top(): void
    {
        $e = $this->staff(18000);

        $this->assertSame(86.26, Wage::forMinutes($e, 60));
        $this->assertSame(107.83, Wage::forOvertimeMinutes($e, 60));   // x 1.25
    }

    public function test_minutes_are_said_in_hours_and_minutes(): void
    {
        $this->assertSame('45m', Wage::sayMinutes(45));
        $this->assertSame('2h', Wage::sayMinutes(120));
        $this->assertSame('3h 20m', Wage::sayMinutes(200));
    }

    /* ---------------- approved AND worked ---------------- */

    public function test_overtime_is_paid_for_what_was_approved_and_worked(): void
    {
        $e = $this->staff();

        $this->clocked($e, '2026-09-14', over: 120);
        $this->approvedOvertime($e, '2026-09-14', '17:00', '19:00');   // 120 min

        $pay = AttendancePay::for($e, '2026-09-01', '2026-09-30');

        $this->assertSame(120, $pay['overtime_paid']);
        $this->assertSame(0, $pay['overtime_unapproved']);
        $this->assertSame(Wage::forOvertimeMinutes($e, 120), $pay['earnings'][0]['amount']);
    }

    /** Staying late off your own bat is not overtime. */
    public function test_overtime_worked_but_never_approved_is_not_paid(): void
    {
        $e = $this->staff();

        $this->clocked($e, '2026-09-14', over: 180);

        $pay = AttendancePay::for($e, '2026-09-01', '2026-09-30');

        $this->assertSame(180, $pay['overtime_worked']);
        $this->assertSame(0, $pay['overtime_approved']);
        $this->assertSame(0, $pay['overtime_paid']);
        $this->assertSame(180, $pay['overtime_unapproved']);
        $this->assertSame([], $pay['earnings']);
    }

    /** And overtime approved but never worked is not paid either. */
    public function test_overtime_approved_but_never_worked_is_not_paid(): void
    {
        $e = $this->staff();

        $this->clocked($e, '2026-09-14', over: 0);
        $this->approvedOvertime($e, '2026-09-14', '17:00', '20:00');

        $pay = AttendancePay::for($e, '2026-09-01', '2026-09-30');

        $this->assertSame(0, $pay['overtime_paid']);
    }

    /** The lesser of the two, when they disagree. */
    public function test_the_lesser_of_approved_and_worked_is_what_is_paid(): void
    {
        $e = $this->staff();

        $this->clocked($e, '2026-09-14', over: 180);
        $this->approvedOvertime($e, '2026-09-14', '17:00', '19:00');   // only 120 approved

        $pay = AttendancePay::for($e, '2026-09-01', '2026-09-30');

        $this->assertSame(120, $pay['overtime_paid']);
        $this->assertSame(60, $pay['overtime_unapproved']);
    }

    /** A request still waiting on the desk authorises nothing. */
    public function test_overtime_still_pending_authorises_nothing(): void
    {
        $e = $this->staff();

        $this->clocked($e, '2026-09-14', over: 120);
        $e->requests()->create([
            'type' => HrRequest::TYPE_OVERTIME,
            'starts_on' => '2026-09-14',
            'starts_at' => '17:00',
            'ends_at' => '19:00',
            'reason' => 'Rush job.',
            'status' => HrRequest::STATUS_PENDING,
        ]);

        $this->assertSame(0, AttendancePay::for($e, '2026-09-01', '2026-09-30')['overtime_paid']);
    }

    /* ---------------- late and undertime ---------------- */

    public function test_late_and_undertime_come_off_at_the_plain_rate(): void
    {
        $e = $this->staff();

        $this->clocked($e, '2026-09-14', late: 30, under: 60);

        $pay = AttendancePay::for($e, '2026-09-01', '2026-09-30');

        $this->assertSame(30, $pay['late']);
        $this->assertSame(60, $pay['undertime']);
        $this->assertSame(Wage::forMinutes($e, 30), $pay['deductions'][0]['amount']);
        $this->assertSame(Wage::forMinutes($e, 60), $pay['deductions'][1]['amount']);
    }

    /**
     * Two lines, not one merged figure: being late and leaving early are
     * different conversations to have with somebody.
     */
    public function test_late_and_undertime_stay_separate_lines(): void
    {
        $e = $this->staff();

        $this->clocked($e, '2026-09-14', late: 30, under: 60);

        $labels = array_column(AttendancePay::for($e, '2026-09-01', '2026-09-30')['deductions'], 'label');

        $this->assertStringContainsString('Late', $labels[0]);
        $this->assertStringContainsString('Undertime', $labels[1]);
    }

    /** Nothing owed is not a line. */
    public function test_a_clean_period_adds_nothing(): void
    {
        $e = $this->staff();

        $this->clocked($e, '2026-09-14');

        $pay = AttendancePay::for($e, '2026-09-01', '2026-09-30');

        $this->assertSame([], $pay['earnings']);
        $this->assertSame([], $pay['deductions']);
    }

    /** Only this period. Last month's lateness was already answered for. */
    public function test_only_the_days_inside_the_period_count(): void
    {
        $e = $this->staff();

        $this->clocked($e, '2026-08-20', late: 500);
        $this->clocked($e, '2026-09-14', late: 30);

        $this->assertSame(30, AttendancePay::for($e, '2026-09-01', '2026-09-30')['late']);
    }

    /* ---------------- on the payslip itself ---------------- */

    public function test_the_payslip_carries_the_clock_without_anybody_typing_it(): void
    {
        $hr = $this->hr();
        $e = $this->staff();

        $this->clocked($e, '2026-09-14', late: 30, over: 120);
        $this->approvedOvertime($e, '2026-09-14', '17:00', '19:00');

        $this->actingAs($hr)->post(route('hr.payslips.store', $e), [
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'gross' => 18000,
        ])->assertRedirect();

        $payslip = HrPayslip::latest('id')->firstOrFail();

        $earnings = array_column($payslip->earnings, 'label');
        $deductions = array_column($payslip->deductions, 'label');

        $this->assertContains('Overtime (2h)', $earnings);
        $this->assertContains('Late (30m)', $deductions);
    }

    /** The net has to move, or the lines are decoration. */
    public function test_the_clock_changes_what_they_are_paid(): void
    {
        $hr = $this->hr();

        $clean = $this->staff();
        $late = $this->staff();

        $this->clocked($clean, '2026-09-14');
        $this->clocked($late, '2026-09-14', late: 240);

        foreach ([$clean, $late] as $e) {
            $this->actingAs($hr)->post(route('hr.payslips.store', $e), [
                'period_start' => '2026-09-01',
                'period_end' => '2026-09-30',
                'gross' => 18000,
            ])->assertRedirect();
        }

        $cleanNet = (float) $clean->payslips()->latest('id')->first()->net;
        $lateNet = (float) $late->payslips()->latest('id')->first()->net;

        $this->assertGreaterThan($lateNet, $cleanNet,
            'four hours of lateness made no difference to the wage');
        $this->assertSame(Wage::forMinutes($late, 240), round($cleanNet - $lateNet, 2));
    }

    /** The desk is told about overtime it never approved rather than it vanishing. */
    public function test_unapproved_overtime_is_reported_not_swallowed(): void
    {
        $hr = $this->hr();
        $e = $this->staff();

        $this->clocked($e, '2026-09-14', over: 180);

        $this->actingAs($hr)->post(route('hr.payslips.store', $e), [
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'gross' => 18000,
        ])->assertRedirect()->assertSessionHas('success',
            fn ($said) => str_contains($said, '3h of overtime was clocked but never approved'));
    }

    /** Unticked, and the clock is left out entirely. */
    public function test_the_clock_can_be_left_off(): void
    {
        $hr = $this->hr();
        $e = $this->staff();

        $this->clocked($e, '2026-09-14', late: 240);

        $this->actingAs($hr)->post(route('hr.payslips.store', $e), [
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'gross' => 18000,
            'attendance' => 0,
        ])->assertRedirect();

        $labels = array_column(HrPayslip::latest('id')->firstOrFail()->deductions, 'label');

        $this->assertNotContains('Late (4h)', $labels);
    }

    /**
     * The statutory tables are written against a MONTHLY figure, and the
     * controller was handing them the raw salary column whatever period it
     * was quoted in - so a daily wage of 600 was taxed as 600 a month.
     */
    public function test_a_daily_wage_is_not_taxed_as_a_monthly_one(): void
    {
        $hr = $this->hr();
        $e = $this->staff(600, 'daily');   // 15,650 a month

        $this->actingAs($hr)->post(route('hr.payslips.store', $e), [
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'gross' => 15650,
        ])->assertRedirect();

        $deductions = collect(HrPayslip::latest('id')->firstOrFail()->deductions)
            ->pluck('amount', 'label');

        // 4.5% of 15,650, not of 600.
        $this->assertSame(704.25, $deductions['SSS']);
    }
}
