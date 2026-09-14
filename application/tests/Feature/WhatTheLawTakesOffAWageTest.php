<?php

namespace Tests\Feature;

use App\Models\HrEmployee;
use App\Models\HrLoan;
use App\Models\HrPayslip;
use App\Models\User;
use App\Support\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SSS, PhilHealth, Pag-IBIG and the tax, worked out rather than typed in.
 *
 * A payslip here took a gross figure and a list of deductions somebody keyed
 * in by hand. Every cutoff, somebody retyped the same four numbers from
 * memory - and a wrong keystroke is somebody's wage. Seeding the test copy I
 * typed "SSS 675, PhilHealth 450" myself, because that was all the payslip
 * could hold, which is the clearest statement of the problem I can give.
 *
 * The rates come from the shop's own HRIS, which had this right while this app
 * did not.
 *
 * The same press now also collects what somebody is paying back on a loan.
 * hr_loans has carried a per_payslip figure since the day it was written and
 * nothing had ever applied it: the instalment was recorded and then never
 * taken, so no balance ever moved on its own.
 */
class WhatTheLawTakesOffAWageTest extends TestCase
{
    use RefreshDatabase;

    private function hr(): User
    {
        return User::factory()->create(['job_role' => User::JOB_HR, 'is_active' => true]);
    }

    private function staff(float $salary = 18000): HrEmployee
    {
        return HrEmployee::create([
            'user_id' => User::factory()->create(['job_role' => 'printer', 'is_active' => true])->id,
            'position' => 'Printer',
            'salary' => $salary,
            'salary_period' => 'monthly',
            'started_on' => now()->subYear(),
        ]);
    }

    private function lineFor(HrPayslip $payslip, string $label): ?array
    {
        foreach ($payslip->deductions ?? [] as $line) {
            if (str_starts_with($line['label'], $label)) {
                // Cast on the way out: json_decode hands back a whole number
                // as an int, so 405 and 405.0 are the same money and not the
                // same type, and every assertSame below would be a false
                // failure about nothing.
                return ['label' => $line['label'], 'amount' => (float) $line['amount']];
            }
        }

        return null;
    }

    /* ---------------- the figures themselves ---------------- */

    /**
     * An ordinary shop wage. SSS is 4.5% of the salary credit, PhilHealth
     * 2.5%, Pag-IBIG 2% of at most ₱10,000 - and at this level the TRAIN
     * tables take no tax at all.
     */
    public function test_the_four_deductions_on_an_ordinary_wage(): void
    {
        $d = PayrollCalculator::deductions(18000);

        $this->assertSame(810.0, $d['sss']);          // 18,000 x 4.5%
        $this->assertSame(450.0, $d['philhealth']);   // 18,000 x 2.5%
        $this->assertSame(200.0, $d['pagibig']);      // capped at 10,000 x 2%
        $this->assertSame(0.0, $d['tax']);            // below the tax floor
    }

    /** The floors bite at the bottom of the scale. */
    public function test_the_floors_hold_a_small_wage_up(): void
    {
        $d = PayrollCalculator::deductions(5000);

        $this->assertSame(225.0, $d['sss']);          // 5,000 is above the 4,000 floor
        $this->assertSame(250.0, $d['philhealth']);   // floored at 10,000 x 2.5%
        $this->assertSame(100.0, $d['pagibig']);      // 5,000 x 2%
    }

    /** And the ceilings hold a large one down. */
    public function test_the_ceilings_cap_a_large_wage(): void
    {
        $d = PayrollCalculator::deductions(120000);

        $this->assertSame(1350.0, $d['sss']);         // capped at 30,000 x 4.5%
        $this->assertSame(2500.0, $d['philhealth']);  // capped at 100,000 x 2.5%
        $this->assertSame(200.0, $d['pagibig']);      // capped at 10,000 x 2%
        $this->assertGreaterThan(0, $d['tax']);
    }

    /** Tax is on what is left after the other three, not on the gross. */
    public function test_tax_is_charged_after_the_contributions(): void
    {
        $d = PayrollCalculator::deductions(30000);

        $taxable = 30000 - $d['sss'] - $d['philhealth'] - $d['pagibig'];

        $this->assertLessThan(30000, $taxable);
        $this->assertSame(round(($taxable - 20833) * 0.15, 2), $d['tax']);
    }

    /* ---------------- a period that is half a month ---------------- */

    /**
     * Contributions are monthly. A shop paying twice a month takes half at
     * each cutoff, and the honest way to say so is the period's share of its
     * own month.
     */
    public function test_a_half_month_takes_half(): void
    {
        $whole = PayrollCalculator::lines(18000, '2026-09-01', '2026-09-30');
        $half = PayrollCalculator::lines(18000, '2026-09-01', '2026-09-15');

        $sssWhole = collect($whole)->firstWhere('label', 'SSS')['amount'];
        $sssHalf = collect($half)->firstWhere('label', 'SSS')['amount'];

        $this->assertSame(810.0, $sssWhole);
        $this->assertSame(405.0, $sssHalf);
    }

    /** Nothing owed is not a line. A zero invites the same question forever. */
    public function test_a_deduction_of_nothing_is_not_written_down(): void
    {
        $lines = PayrollCalculator::lines(18000, '2026-09-01', '2026-09-30');

        $this->assertNull(collect($lines)->firstWhere('label', 'Withholding tax'),
            'a zero tax line was written onto the payslip');
    }

    /* ---------------- on a real payslip ---------------- */

    public function test_the_payslip_carries_them_without_anybody_typing_them(): void
    {
        $hr = $this->hr();
        $employee = $this->staff(18000);

        $this->actingAs($hr)->post(route('hr.payslips.store', $employee), [
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-15',
            'gross' => 9000,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $payslip = HrPayslip::latest('id')->firstOrFail();

        $this->assertSame(405.0, $this->lineFor($payslip, 'SSS')['amount']);
        $this->assertSame(225.0, $this->lineFor($payslip, 'PhilHealth')['amount']);
        $this->assertSame(100.0, $this->lineFor($payslip, 'Pag-IBIG')['amount']);

        // 9,000 gross less 730 taken off.
        $this->assertSame(8270.0, round((float) $payslip->net, 2));
    }

    /** What the officer typed is kept beside what was worked out. */
    public function test_a_typed_deduction_survives_alongside_them(): void
    {
        $hr = $this->hr();
        $employee = $this->staff(18000);

        $this->actingAs($hr)->post(route('hr.payslips.store', $employee), [
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-15',
            'gross' => 9000,
            'deductions' => [['label' => 'Uniform', 'amount' => 350]],
        ])->assertRedirect();

        $payslip = HrPayslip::latest('id')->firstOrFail();

        $this->assertSame(350.0, $this->lineFor($payslip, 'Uniform')['amount']);
        $this->assertNotNull($this->lineFor($payslip, 'SSS'));
    }

    /** And it can be turned off for a payslip that is not a wage run. */
    public function test_the_statutory_lines_can_be_left_off(): void
    {
        $hr = $this->hr();
        $employee = $this->staff(18000);

        $this->actingAs($hr)->post(route('hr.payslips.store', $employee), [
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-15',
            'gross' => 9000,
            'statutory' => 0,
        ])->assertRedirect();

        $payslip = HrPayslip::latest('id')->firstOrFail();

        $this->assertNull($this->lineFor($payslip, 'SSS'));
        $this->assertSame(9000.0, round((float) $payslip->net, 2));
    }

    /* ---------------- the loan actually gets paid ---------------- */

    public function test_a_loan_instalment_is_taken_and_the_balance_moves(): void
    {
        $hr = $this->hr();
        $employee = $this->staff(18000);

        $loan = HrLoan::create([
            'hr_employee_id' => $employee->id,
            'principal' => 5000,
            'reason' => 'Tuition',
            'borrowed_on' => now()->subMonth(),
            'per_payslip' => 500,
        ]);

        $this->assertSame(5000.0, $loan->balance());

        $this->actingAs($hr)->post(route('hr.payslips.store', $employee), [
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-15',
            'gross' => 9000,
            'statutory' => 0,
        ])->assertRedirect();

        $payslip = HrPayslip::latest('id')->firstOrFail();

        $this->assertSame(500.0, $this->lineFor($payslip, 'Loan repayment')['amount'],
            'the instalment was recorded on the loan and never taken off a wage');
        $this->assertSame(4500.0, $loan->fresh()->balance(),
            'it came off the wage and the balance did not move');
        $this->assertSame(8500.0, round((float) $payslip->net, 2));
    }

    /**
     * Never more than is left owing. A ₱500 instalment against ₱200
     * outstanding takes ₱200 and settles it, rather than taking ₱500 and
     * leaving the shop owing them ₱300 with nothing saying so.
     */
    public function test_the_last_instalment_takes_only_what_is_left(): void
    {
        $hr = $this->hr();
        $employee = $this->staff(18000);

        $loan = HrLoan::create([
            'hr_employee_id' => $employee->id,
            'principal' => 700,
            'borrowed_on' => now()->subMonth(),
            'per_payslip' => 500,
        ]);

        $loan->payments()->create(['amount' => 500, 'paid_on' => now()->subWeek()]);
        $this->assertSame(200.0, $loan->fresh()->balance());

        $this->actingAs($hr)->post(route('hr.payslips.store', $employee), [
            'period_start' => '2026-09-01', 'period_end' => '2026-09-15',
            'gross' => 9000, 'statutory' => 0,
        ])->assertRedirect();

        $payslip = HrPayslip::latest('id')->firstOrFail();

        $this->assertSame(200.0, $this->lineFor($payslip, 'Loan repayment')['amount']);
        $this->assertTrue($loan->fresh()->isSettled());
    }

    /** A settled loan is not taken off again. */
    public function test_a_settled_loan_is_left_alone(): void
    {
        $hr = $this->hr();
        $employee = $this->staff(18000);

        $loan = HrLoan::create([
            'hr_employee_id' => $employee->id,
            'principal' => 500,
            'borrowed_on' => now()->subMonth(),
            'per_payslip' => 500,
        ]);
        $loan->payments()->create(['amount' => 500, 'paid_on' => now()->subWeek()]);

        $this->actingAs($hr)->post(route('hr.payslips.store', $employee), [
            'period_start' => '2026-09-01', 'period_end' => '2026-09-15',
            'gross' => 9000, 'statutory' => 0,
        ])->assertRedirect();

        $payslip = HrPayslip::latest('id')->firstOrFail();

        $this->assertNull($this->lineFor($payslip, 'Loan repayment'));
        $this->assertSame(9000.0, round((float) $payslip->net, 2));
        $this->assertSame(1, $loan->fresh()->payments()->count(), 'it was paid twice');
    }

    /** A loan with no instalment set is being paid by hand, so it is left. */
    public function test_a_loan_with_no_instalment_is_not_collected(): void
    {
        $hr = $this->hr();
        $employee = $this->staff(18000);

        $loan = HrLoan::create([
            'hr_employee_id' => $employee->id,
            'principal' => 5000,
            'borrowed_on' => now()->subMonth(),
            'per_payslip' => 0,
        ]);

        $this->actingAs($hr)->post(route('hr.payslips.store', $employee), [
            'period_start' => '2026-09-01', 'period_end' => '2026-09-15',
            'gross' => 9000, 'statutory' => 0,
        ])->assertRedirect();

        $this->assertSame(5000.0, $loan->fresh()->balance());
        $this->assertSame(0, $loan->fresh()->payments()->count());
    }
}
