<?php

namespace App\Support;

use App\Models\Attendance;
use App\Models\HrEmployee;
use App\Models\HrRequest;
use Illuminate\Support\Carbon;

/**
 * What the clock is worth on a payslip.
 *
 * The clock recorded late, undertime and overtime minutes and the payslip
 * read none of them. Somebody 419 minutes late over a fortnight got the same
 * wage as somebody never late, and approved overtime was never paid. Numbers
 * that visibly do not matter are how people learn to stop pressing the button.
 *
 * Overtime is paid for what was BOTH approved and worked, never the larger of
 * the two. Paying whatever the clock says would pay anybody who chose to stay
 * late; paying whatever was approved would pay for overtime nobody did. The
 * lesser of the two is the only figure that is true either way, and what is
 * clocked beyond it is reported so the desk can see it rather than silently
 * dropped.
 *
 * Late and undertime are deducted at the plain rate with no premium, which is
 * the whole of the shop's rule on them.
 */
class AttendancePay
{
    /**
     * Everything the clock says about one pay period.
     *
     * @return array{
     *     late: int, undertime: int,
     *     overtime_worked: int, overtime_approved: int, overtime_paid: int,
     *     overtime_unapproved: int,
     *     earnings: array<int, array{label: string, amount: float}>,
     *     deductions: array<int, array{label: string, amount: float}>
     * }
     */
    public static function for(HrEmployee $employee, $from, $to): array
    {
        $from = Carbon::parse($from)->toDateString();
        $to = Carbon::parse($to)->toDateString();

        $clock = Attendance::where('user_id', $employee->user_id)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->get();

        $late = (int) $clock->sum('late_minutes');
        $undertime = (int) $clock->sum('undertime_minutes');
        $worked = (int) $clock->sum('overtime_minutes');

        $approved = self::approvedOvertimeMinutes($employee, $from, $to);
        $paid = min($worked, $approved);

        $earnings = [];
        $deductions = [];

        if ($paid > 0) {
            $earnings[] = [
                'label' => 'Overtime ('.Wage::sayMinutes($paid).')',
                'amount' => Wage::forOvertimeMinutes($employee, $paid),
            ];
        }

        // Late and undertime are one deduction or two, never a merged figure:
        // being late and leaving early are different conversations to have
        // with somebody, and a single "attendance" line hides which happened.
        if ($late > 0) {
            $deductions[] = [
                'label' => 'Late ('.Wage::sayMinutes($late).')',
                'amount' => Wage::forMinutes($employee, $late),
            ];
        }

        if ($undertime > 0) {
            $deductions[] = [
                'label' => 'Undertime ('.Wage::sayMinutes($undertime).')',
                'amount' => Wage::forMinutes($employee, $undertime),
            ];
        }

        return [
            'late' => $late,
            'undertime' => $undertime,
            'overtime_worked' => $worked,
            'overtime_approved' => $approved,
            'overtime_paid' => $paid,
            'overtime_unapproved' => max(0, $worked - $paid),
            'earnings' => $earnings,
            'deductions' => $deductions,
        ];
    }

    /**
     * Overtime the desk actually said yes to, in minutes.
     *
     * Matched on the day the overtime was for rather than when it was filed,
     * so a request approved after the cutoff still belongs to the period it
     * was worked in.
     */
    public static function approvedOvertimeMinutes(HrEmployee $employee, $from, $to): int
    {
        return (int) $employee->requests()
            ->where('type', HrRequest::TYPE_OVERTIME)
            ->where('status', HrRequest::STATUS_APPROVED)
            ->whereDate('starts_on', '>=', Carbon::parse($from)->toDateString())
            ->whereDate('starts_on', '<=', Carbon::parse($to)->toDateString())
            ->get()
            ->sum(fn (HrRequest $r) => $r->minutes() ?? 0);
    }
}
