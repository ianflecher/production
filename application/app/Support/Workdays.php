<?php

namespace App\Support;

use App\Models\Holiday;
use Illuminate\Support\Carbon;

/**
 * How many days off a stretch of dates actually costs.
 *
 * Adapted from the shop's own HRIS. A leave request carried two dates and
 * nothing counted what lay between them: Friday to Monday is two days of
 * leave, not four, and over Holy Week it may be one. The desk worked that out
 * on paper each time, and nothing on the request said which answer had been
 * used - so a balance could never be trusted either.
 *
 * Sunday is the shop's rest day and Saturday is worked, which is why this
 * counts six days a week rather than five. Change it here if that changes.
 */
class Workdays
{
    /** The one day a week the shop is shut. */
    public const REST_DAY = Carbon::SUNDAY;

    /**
     * Working days between two dates, both ends included.
     *
     * Sundays and anything in the holidays table are not worked, so they are
     * not leave. A range that runs backwards is nothing rather than a
     * negative: it is a mistake, and a mistake should not credit anybody.
     */
    public static function between($start, $end): int
    {
        $from = Carbon::parse($start)->startOfDay();
        $to = Carbon::parse($end)->startOfDay();

        if ($to->lessThan($from)) {
            return 0;
        }

        $shut = Holiday::between($from, $to);

        $days = 0;

        for ($day = $from->copy(); $day->lessThanOrEqualTo($to); $day->addDay()) {
            if (self::isWorked($day, $shut)) {
                $days++;
            }
        }

        return $days;
    }

    /** Is the shop open on this day? */
    public static function isWorked(Carbon $day, ?array $shut = null): bool
    {
        if ($day->dayOfWeek === self::REST_DAY) {
            return false;
        }

        $shut ??= Holiday::between($day, $day);

        return ! in_array($day->toDateString(), $shut, true);
    }

    /**
     * Working days in the month a date falls in — what a monthly wage buys.
     */
    public static function inMonthOf($date): int
    {
        $day = Carbon::parse($date);

        return self::between($day->copy()->startOfMonth(), $day->copy()->endOfMonth());
    }
}
