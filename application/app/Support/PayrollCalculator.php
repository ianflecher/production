<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * What the law takes off a wage, worked out rather than typed in.
 *
 * Adapted from the shop's own HRIS (imprintcustomsecommerce-cell/hris), which
 * had this right while this app did not: a payslip here took a gross figure
 * and a list of deductions somebody keyed in by hand. Every cutoff, somebody
 * retyped SSS and PhilHealth from memory, and a wrong keystroke is somebody's
 * wage.
 *
 * The rates live here and nowhere else, so when a contribution table changes
 * there is one place to change it.
 *
 * These are the employee's share only. The employer's counterpart is the
 * shop's cost and never comes off a wage, so it is not computed here.
 */
class PayrollCalculator
{
    /** Below this monthly take, the TRAIN tables take nothing. */
    public const TAX_FREE_MONTHLY = 20833;

    /**
     * The four statutory deductions on a monthly basic salary.
     *
     * @return array{sss: float, philhealth: float, pagibig: float, tax: float}
     */
    public static function deductions(float $monthlyBasic): array
    {
        $monthlyBasic = max(0, $monthlyBasic);

        $sss = self::sss($monthlyBasic);
        $philhealth = self::philhealth($monthlyBasic);
        $pagibig = self::pagibig($monthlyBasic);

        // Tax is on what is left after the other three, not on the gross.
        $taxable = max(0, $monthlyBasic - $sss - $philhealth - $pagibig);

        return [
            'sss' => round($sss, 2),
            'philhealth' => round($philhealth, 2),
            'pagibig' => round($pagibig, 2),
            'tax' => round(self::withholdingTax($taxable), 2),
        ];
    }

    /**
     * The same four as payslip lines, for a period that may be half a month.
     *
     * Contributions are monthly amounts. A shop paying twice a month takes
     * half at each cutoff, and the honest way to say that is the period's
     * share of its own month — fifteen days of a thirty-day month is a half,
     * and a full month is the whole.
     *
     * Returns the shape hr_payslips already stores: [{label, amount}].
     *
     * @return array<int, array{label: string, amount: float}>
     */
    public static function lines(float $monthlyBasic, $periodStart = null, $periodEnd = null): array
    {
        $share = self::shareOfMonth($periodStart, $periodEnd);

        $named = [
            'sss' => 'SSS',
            'philhealth' => 'PhilHealth',
            'pagibig' => 'Pag-IBIG',
            'tax' => 'Withholding tax',
        ];

        $lines = [];

        foreach (self::deductions($monthlyBasic) as $key => $amount) {
            $due = round($amount * $share, 2);

            // Nothing owed is not a line. A zero on a payslip invites the
            // question "why is that there", every cutoff, forever.
            if ($due > 0) {
                $lines[] = ['label' => $named[$key], 'amount' => $due];
            }
        }

        return $lines;
    }

    /**
     * How much of a month this period covers, as a fraction between 0 and 1.
     *
     * No dates at all means a whole month: the caller is asking for the
     * monthly figures and saying so by not narrowing them.
     */
    public static function shareOfMonth($start = null, $end = null): float
    {
        if (! $start || ! $end) {
            return 1.0;
        }

        $from = Carbon::parse($start)->startOfDay();
        $to = Carbon::parse($end)->startOfDay();

        if ($to->lessThan($from)) {
            return 0.0;
        }

        $days = $from->diffInDays($to) + 1;       // inclusive of both ends
        $inMonth = (int) $from->daysInMonth;

        return round(min(1.0, $days / max(1, $inMonth)), 4);
    }

    /** SSS employee share: 4.5% of the salary credit, floored and capped. */
    protected static function sss(float $basic): float
    {
        return min(max($basic, 4000), 30000) * 0.045;
    }

    /** PhilHealth: a 5% premium split evenly, so 2.5% on ₱10k–₱100k. */
    protected static function philhealth(float $basic): float
    {
        return min(max($basic, 10000), 100000) * 0.025;
    }

    /** Pag-IBIG: 1% at or under ₱1,500, else 2%, on at most ₱10,000. */
    protected static function pagibig(float $basic): float
    {
        return min($basic, 10000) * ($basic <= 1500 ? 0.01 : 0.02);
    }

    /** Monthly withholding, on the TRAIN tables in force from 2023. */
    protected static function withholdingTax(float $taxable): float
    {
        return match (true) {
            $taxable <= 20833 => 0,
            $taxable <= 33332 => ($taxable - 20833) * 0.15,
            $taxable <= 66666 => 1875 + ($taxable - 33333) * 0.20,
            $taxable <= 166666 => 8541.80 + ($taxable - 66667) * 0.25,
            $taxable <= 666666 => 33541.80 + ($taxable - 166667) * 0.30,
            default => 183541.80 + ($taxable - 666667) * 0.35,
        };
    }
}
