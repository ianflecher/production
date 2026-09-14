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
 * The shop grants vacation and sick days separately, so they are counted
 * separately. Sick leave used to be filed as plain leave and drawn off the
 * vacation allowance, which quietly spent somebody's holiday on being ill -
 * and left sick_credits sitting in the table meaning nothing.
 *
 * Only days AWAY from work draw anything down. A change of schedule,
 * overtime, undertime and official business are arrangements about a working
 * day rather than days away from one, so they are counted nowhere and refused
 * by nothing.
 *
 * An allowance nobody has set is not the same as an allowance of none. A
 * person with no figure against their name has no balance to be over, which
 * is the right answer for staff who predate the HR desk - the alternative is
 * telling somebody they have used up an allowance nobody ever gave them.
 */
class LeaveBalance
{
    /**
     * The vacation balance, or null when nobody has set an allowance.
     *
     * @return array{allowed: int, taken: float, left: float}|null
     */
    public static function for(HrEmployee $employee, ?int $year = null): ?array
    {
        return self::of($employee, HrRequest::TYPE_LEAVE, $year);
    }

    /**
     * The sick balance, or null when nobody has set an allowance.
     *
     * @return array{allowed: int, taken: float, left: float}|null
     */
    public static function sick(HrEmployee $employee, ?int $year = null): ?array
    {
        return self::of($employee, HrRequest::TYPE_SICK, $year);
    }

    /**
     * One kind of leave, counted against the allowance it draws on.
     *
     * @return array{allowed: int, taken: float, left: float}|null
     */
    public static function of(HrEmployee $employee, string $type, ?int $year = null): ?array
    {
        $column = HrRequest::DRAWS_ON[$type] ?? null;

        // A type that draws on nothing has no balance to report, which is not
        // the same as a balance of zero.
        if ($column === null) {
            return null;
        }

        $allowed = $employee->{$column};

        if ($allowed === null) {
            return null;
        }

        $taken = self::taken($employee, $type, $year);

        return [
            'allowed' => (int) $allowed,
            'taken' => $taken,
            'left' => round(max(0, $allowed - $taken), 2),
        ];
    }

    /**
     * Days of this kind already approved this year.
     *
     * Counted off working_days, which is what the request was decided on -
     * not recounted from the dates, because the holiday calendar can change
     * afterwards and the number somebody was granted should not move.
     */
    public static function taken(HrEmployee $employee, string $type = HrRequest::TYPE_LEAVE, ?int $year = null): float
    {
        $year ??= (int) now()->format('Y');

        return round((float) $employee->requests()
            ->where('type', $type)
            ->where('status', HrRequest::STATUS_APPROVED)
            ->whereYear('starts_on', $year)
            ->sum('working_days'), 2);
    }

    /**
     * Would approving this request take them past the allowance it draws on?
     *
     * False when nobody has set one: there is no line to cross. False too for
     * the hourly kinds, which draw on nothing.
     */
    public static function wouldOverdraw(HrRequest $request): bool
    {
        $employee = $request->employee;

        if (! $employee) {
            return false;
        }

        $balance = self::of($employee, $request->type, (int) $request->starts_on?->format('Y'));

        if ($balance === null) {
            return false;
        }

        return (float) $request->working_days > $balance['left'];
    }

    /** "vacation leave" / "sick leave", for saying which allowance was overdrawn. */
    public static function nameOf(string $type): string
    {
        return $type === HrRequest::TYPE_SICK ? 'sick leave' : 'vacation leave';
    }
}
