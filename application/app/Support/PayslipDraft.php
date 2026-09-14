<?php

namespace App\Support;

use App\Models\Attendance;
use App\Models\HrEmployee;
use App\Models\HrLoan;
use App\Models\HrPayslip;
use Illuminate\Support\Carbon;

/**
 * How a payslip is assembled, in one place.
 *
 * Three things are worked out rather than typed - the statutory deductions,
 * the loan instalments due, and what the clock says - and each was written
 * into the controller action that records one payslip by hand. A cut-off that
 * does thirty of them at once must produce exactly the same figures, and the
 * only way to be sure of that is for both to call the same code.
 *
 * Nothing here writes anything. A draft can be shown to the desk, totalled,
 * and thrown away, which is what makes a cut-off previewable before it is
 * committed to thirty people's wages.
 */
class PayslipDraft
{
    /**
     * Everything a payslip for this person and period would contain.
     *
     * @param  array{statutory?: bool, loans?: bool, attendance?: bool}  $include
     * @param  array<int, array{label: string, amount: float}>  $typedEarnings
     * @param  array<int, array{label: string, amount: float}>  $typedDeductions
     */
    public static function for(
        HrEmployee $employee,
        $start,
        $end,
        ?float $gross = null,
        array $include = [],
        array $typedEarnings = [],
        array $typedDeductions = [],
    ): array {
        $statutoryOn = $include['statutory'] ?? true;
        $loansOn = $include['loans'] ?? true;
        $clockOn = $include['attendance'] ?? true;

        $gross ??= self::suggestedGross($employee, $start, $end);

        // Worked out rather than typed: SSS, PhilHealth, Pag-IBIG and the
        // withholding tax, off a MONTHLY figure and shared down to the period.
        $statutory = $statutoryOn
            ? PayrollCalculator::lines(Wage::monthly($employee), $start, $end)
            : [];

        // What they are paying back this cut-off, never more than is left
        // owing. The loan rows are carried alongside the lines because a
        // deduction is only half of a repayment - see commit().
        $repayments = $loansOn ? self::loanRepayments($employee) : [];

        $clock = $clockOn ? AttendancePay::for($employee, $start, $end) : null;

        $earnings = array_merge($typedEarnings, $clock['earnings'] ?? []);

        $deductions = array_merge(
            $typedDeductions,
            $statutory,
            $clock['deductions'] ?? [],
            array_map(fn ($r) => $r['line'], $repayments),
        );

        return [
            'employee' => $employee,
            'period_start' => Carbon::parse($start)->toDateString(),
            'period_end' => Carbon::parse($end)->toDateString(),
            'gross' => round($gross, 2),
            'earnings' => $earnings,
            'deductions' => $deductions,
            'net' => round(
                $gross + HrPayslip::sumLines($earnings) - HrPayslip::sumLines($deductions),
                2
            ),
            'repayments' => $repayments,
            'clock' => $clock,
        ];
    }

    /**
     * What the gross would be if nobody typed one.
     *
     * A monthly wage is shared down to the part of the month the period
     * covers, so a fortnight pays half. A daily wage is the rate times the
     * days they were actually there, because that is what a daily wage means
     * - and the clock is the only record of which days those were.
     */
    public static function suggestedGross(HrEmployee $employee, $start, $end): float
    {
        if ($employee->salary_period === 'daily') {
            return round(Wage::daily($employee) * self::daysPresent($employee, $start, $end), 2);
        }

        return round(Wage::monthly($employee) * PayrollCalculator::shareOfMonth($start, $end), 2);
    }

    /** Days the clock says they were here. */
    public static function daysPresent(HrEmployee $employee, $start, $end): int
    {
        return Attendance::where('user_id', $employee->user_id)
            ->where('status', 'present')
            ->whereDate('date', '>=', Carbon::parse($start)->toDateString())
            ->whereDate('date', '<=', Carbon::parse($end)->toDateString())
            ->count();
    }

    /**
     * What this person is paying back this cut-off, per open loan.
     *
     * Never more than is left owing: a 500 instalment against 200 outstanding
     * takes 200 and settles it, rather than taking 500 and leaving the shop
     * owing them 300.
     *
     * @return array<int, array{loan: HrLoan, line: array{label: string, amount: float}}>
     */
    public static function loanRepayments(HrEmployee $employee): array
    {
        $out = [];

        foreach ($employee->loans()->get() as $loan) {
            $instalment = round((float) $loan->per_payslip, 2);
            $left = $loan->balance();

            if ($instalment <= 0 || $left <= 0) {
                continue;
            }

            $out[] = [
                'loan' => $loan,
                'line' => [
                    'label' => 'Loan repayment'.($loan->reason ? ' ('.$loan->reason.')' : ''),
                    'amount' => round(min($instalment, $left), 2),
                ],
            ];
        }

        return $out;
    }

    /**
     * Turn a draft into a real payslip, and record the loan payments with it.
     *
     * The two go together or neither does. Without the payment rows the loan
     * would be taken off the wage every cut-off for ever and the balance
     * would never move.
     */
    public static function commit(array $draft, int $by, bool $release = false, ?string $note = null): HrPayslip
    {
        $payslip = new HrPayslip([
            'hr_employee_id' => $draft['employee']->id,
            'period_start' => $draft['period_start'],
            'period_end' => $draft['period_end'],
            'gross' => $draft['gross'],
            'earnings' => $draft['earnings'],
            'deductions' => $draft['deductions'],
            'note' => $note,
            'created_by' => $by,
        ]);

        $payslip->recomputeNet();

        if ($release) {
            $payslip->released_at = now();
        }

        $payslip->save();

        foreach ($draft['repayments'] as $repayment) {
            $repayment['loan']->payments()->create([
                'amount' => $repayment['line']['amount'],
                'paid_on' => $payslip->period_end,
                'note' => 'From the payslip for '
                    .$payslip->period_start->format('M j').'–'.$payslip->period_end->format('M j, Y'),
                'recorded_by' => $by,
            ]);
        }

        return $payslip;
    }
}
