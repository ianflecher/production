<?php

namespace App\Support;

use App\Models\HrEmployee;
use App\Models\HrRequest;

/**
 * What leave somebody has left this year.
 *
 * Adapted from the shop's own HRIS. Nothing here counted leave at all: a
 * person could file every week and the only thing between them and it was
 * somebody remembering how much they had already taken. The desk had no
 * number to refuse with and the person had no number to plan around.
 *
 * Only leave draws down a balance. A change of schedule, overtime, undertime
 * and official business are arrangements about a working day rather than days
 * away from one, so they are counted nowhere and refused by nothing.
 *
 * An allowance nobody has set is not the same as an allowance of none. A
 * person with no figure against their name has no balance to be over, which
 * is the right answer for staff who predate the HR desk - the alternative is
 * telling somebody they have used up an allowance nobody ever gave them.
 */
class LeaveBalance
{
    /**
     * The balance for one employee this year, or null when nobody has set one.
     *
     * @return array{allowed: int, taken: float, left: float}|null
     */
    public static function for(HrEmployee $employee, ?int $year = null): ?array
    {
        $allowed = $employee->vacation_credits;

        if ($allowed === null) {
            return null;
        }

        $taken = self::taken($employee, $year);

        return [
            'allowed' => (int) $allowed,
            'taken' => $taken,
            'left' => round(max(0, $allowed - $taken), 2),
        ];
    }

    /**
     * Days of leave already approved this year.
     *
     * Counted off working_days, which is what the request was decided on -
     * not recounted from the dates, because the holiday calendar can change
     * afterwards and the number somebody was granted should not move.
     */
    public static function taken(HrEmployee $employee, ?int $year = null): float
    {
        $year ??= (int) now()->format('Y');

        return round((float) $employee->requests()
            ->where('type', HrRequest::TYPE_LEAVE)
            ->where('status', HrRequest::STATUS_APPROVED)
            ->whereYear('starts_on', $year)
            ->sum('working_days'), 2);
    }

    /**
     * Would approving this request take them past their allowance?
     *
     * False when nobody has set one: there is no line to cross.
     */
    public static function wouldOverdraw(HrRequest $request): bool
    {
        if ($request->type !== HrRequest::TYPE_LEAVE) {
            return false;
        }

        $employee = $request->employee;

        if (! $employee) {
            return false;
        }

        $balance = self::for($employee, (int) $request->starts_on?->format('Y'));

        if ($balance === null) {
            return false;
        }

        return (float) $request->working_days > $balance['left'];
    }
}
