<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * How a day's timekeeping measures against the working day.
 *
 * Adapted from the shop's own HRIS, which kept time-in and time-out and could
 * therefore say who was late and by how much. This app had a present/absent
 * flag and nothing else, so every one of those questions was settled from
 * memory.
 *
 * Three numbers come out, and they are distances rather than directions:
 *   late       minutes past the start of the shift somebody arrived
 *   undertime  minutes before the end of the shift somebody left
 *   overtime   minutes past the end of the shift somebody stayed
 *
 * Undertime and overtime are exclusive of each other by construction - you
 * cannot leave both early and late - but late sits alongside either.
 */
class Shift
{
    /** The scheduled start, on the day being measured. */
    public static function start(?string $onDate = null): Carbon
    {
        return self::at(config('shift.start', '08:00'), $onDate);
    }

    /** The scheduled end, on the day being measured. */
    public static function end(?string $onDate = null): Carbon
    {
        return self::at(config('shift.end', '17:00'), $onDate);
    }

    /** Minutes past the start that are forgiven entirely. */
    public static function grace(): int
    {
        return (int) config('shift.grace_minutes', 0);
    }

    /**
     * Late / undertime / overtime for one day's clock times.
     *
     * Times are "H:i" or "H:i:s"; either may be missing, and a missing one
     * simply measures nothing rather than counting as zero minutes worked.
     * Both are read against the SAME date so that a shift end of 17:00 is not
     * compared with a time-out that Carbon parsed onto some other day.
     *
     * @return array{late: int, undertime: int, overtime: int}
     */
    public static function metrics(?string $timeIn, ?string $timeOut, ?string $onDate = null): array
    {
        $late = $undertime = $overtime = 0;

        if ($timeIn !== null && $timeIn !== '') {
            $in = self::at($timeIn, $onDate);

            // Past the grace, and the lateness counts from the start of the
            // shift - the grace forgives a late arrival or it does not.
            if ($in->gt(self::start($onDate)->addMinutes(self::grace()))) {
                $late = self::start($onDate)->diffInMinutes($in);
            }
        }

        if ($timeOut !== null && $timeOut !== '') {
            $out = self::at($timeOut, $onDate);
            $end = self::end($onDate);

            if ($out->lt($end)) {
                $undertime = $out->diffInMinutes($end);
            } elseif ($out->gt($end)) {
                $overtime = $end->diffInMinutes($out);
            }
        }

        return [
            'late' => self::minutes($late),
            'undertime' => self::minutes($undertime),
            'overtime' => self::minutes($overtime),
        ];
    }

    /** Whether an arrival counts as late at all - past the start AND the grace. */
    public static function isLate(?string $timeIn, ?string $onDate = null): bool
    {
        if ($timeIn === null || $timeIn === '') {
            return false;
        }

        return self::at($timeIn, $onDate)
            ->gt(self::start($onDate)->addMinutes(self::grace()));
    }

    /** A clock time read onto a particular day, so two of them are comparable. */
    private static function at(string $time, ?string $onDate = null): Carbon
    {
        $day = $onDate ? Carbon::parse($onDate) : Carbon::today();

        return $day->copy()->setTimeFromTimeString($time);
    }

    /**
     * Carbon 3 hands back a signed float. The sign is already settled by the
     * comparison that got us here, and a part-minute is rounded rather than
     * dropped so that thirty seconds either way does not vanish.
     */
    private static function minutes(int|float $raw): int
    {
        return (int) round(abs($raw));
    }
}
