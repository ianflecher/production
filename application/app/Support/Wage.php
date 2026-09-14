<?php

namespace App\Support;

use App\Models\HrEmployee;

/**
 * What an hour of somebody's time is worth.
 *
 * Nothing could answer this before, which is why attendance and payroll never
 * met: the clock knew a person was ninety minutes late and the payslip had no
 * way to turn ninety minutes into pesos.
 *
 * Everything is derived from a MONTHLY figure, because that is what the
 * statutory tables want too - see PayrollCalculator, which was being handed
 * hr_employees.salary raw regardless of the period it was quoted in. A daily
 * wage of 600 was being taxed as though it were 600 a month.
 */
class Wage
{
    /**
     * Paid days in a year for a six-day week.
     *
     * 365 less the 52 Sundays the shop rests on. This is the divisor the
     * Philippine rules use for monthly-paid staff on a six-day schedule; a
     * five-day shop would use 261. Workdays::REST_DAY is the other half of
     * this assumption and the two must not drift apart.
     */
    public const DAYS_A_YEAR = 313;

    public const HOURS_A_DAY = 8;

    /**
     * Overtime on an ordinary day is the hourly rate plus a quarter.
     *
     * Rest days and holidays carry higher premiums that this does not
     * attempt - the shop rarely works them, and getting a holiday premium
     * quietly wrong is worse than leaving it to be typed by hand.
     */
    public const OVERTIME_MULTIPLIER = 1.25;

    /** The salary as a monthly figure, whatever period it is quoted in. */
    public static function monthly(HrEmployee $employee): float
    {
        $salary = (float) ($employee->salary ?? 0);

        return round(match ($employee->salary_period) {
            'semi_monthly' => $salary * 2,
            'daily' => $salary * self::DAYS_A_YEAR / 12,
            default => $salary,
        }, 2);
    }

    /** One day's pay. */
    public static function daily(HrEmployee $employee): float
    {
        if ($employee->salary_period === 'daily') {
            return round((float) ($employee->salary ?? 0), 2);
        }

        return round(self::monthly($employee) * 12 / self::DAYS_A_YEAR, 2);
    }

    /** One hour's pay, which is what every attendance minute is priced from. */
    public static function hourly(HrEmployee $employee): float
    {
        return round(self::daily($employee) / self::HOURS_A_DAY, 2);
    }

    /** What a stretch of minutes is worth at the plain rate. */
    public static function forMinutes(HrEmployee $employee, int $minutes): float
    {
        return round(self::hourly($employee) * max(0, $minutes) / 60, 2);
    }

    /** The same stretch worked past the end of the shift, at the premium. */
    public static function forOvertimeMinutes(HrEmployee $employee, int $minutes): float
    {
        return round(self::forMinutes($employee, $minutes) * self::OVERTIME_MULTIPLIER, 2);
    }

    /** "3h 20m", or "45m" - for saying on a payslip line what is being paid for. */
    public static function sayMinutes(int $minutes): string
    {
        $minutes = max(0, $minutes);
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        if ($hours === 0) {
            return $rest.'m';
        }

        return $rest === 0 ? $hours.'h' : $hours.'h '.$rest.'m';
    }
}
