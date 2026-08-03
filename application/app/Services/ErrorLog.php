<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Reads Laravel's log so problems can be seen without anyone having to open a
 * 1 MB text file on the server. Errors are grouped, so "the same thing failed
 * 40 times" reads as one row with a count rather than 40 rows.
 */
class ErrorLog
{
    /** Never read more than this from the end of the file. */
    private const MAX_BYTES = 2_097_152;   // 2 MB

    /** Entries written while running the test suite aren't real incidents. */
    private const IGNORED_ENVIRONMENTS = ['testing'];

    public static function path(): string
    {
        return storage_path('logs/laravel.log');
    }

    /**
     * Grouped errors from the last $days, worst-repeating first.
     *
     * @return Collection<int, array{level:string, environment:string, message:string, count:int, first:Carbon, last:Carbon}>
     */
    public static function recent(int $days = 7): Collection
    {
        $since = now()->subDays($days);
        $groups = [];

        foreach (self::entries() as $entry) {
            if ($entry['at']->lt($since)) {
                continue;
            }

            // Group on the message itself so the same failure collapses to one
            // row however many times it happened.
            $key = $entry['level'].'|'.$entry['message'];

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'level' => $entry['level'],
                    'environment' => $entry['environment'],
                    'message' => $entry['message'],
                    'count' => 0,
                    'first' => $entry['at'],
                    'last' => $entry['at'],
                ];
            }

            $groups[$key]['count']++;
            $groups[$key]['first'] = $entry['at']->lt($groups[$key]['first']) ? $entry['at'] : $groups[$key]['first'];
            $groups[$key]['last'] = $entry['at']->gt($groups[$key]['last']) ? $entry['at'] : $groups[$key]['last'];
        }

        return collect(array_values($groups))
            ->sortByDesc(fn ($g) => $g['last']->getTimestamp())
            ->values();
    }

    /** How many errors were logged in the last $days (for a badge). */
    public static function countRecent(int $days = 7): int
    {
        return (int) self::recent($days)->sum('count');
    }

    /**
     * Every ERROR/CRITICAL/ALERT/EMERGENCY line in the tail of the log.
     *
     * @return array<int, array{at:Carbon, environment:string, level:string, message:string}>
     */
    private static function entries(): array
    {
        $file = self::path();

        if (! is_file($file) || ! is_readable($file)) {
            return [];
        }

        $text = self::tail($file, self::MAX_BYTES);

        // [2026-08-03 08:53:50] production.ERROR: the message…
        $pattern = '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] ([a-z_]+)\.(ERROR|CRITICAL|ALERT|EMERGENCY): (.*)$/m';

        if (! preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $entries = [];

        foreach ($matches as $m) {
            if (in_array($m[2], self::IGNORED_ENVIRONMENTS, true)) {
                continue;
            }

            try {
                $at = Carbon::createFromFormat('Y-m-d H:i:s', $m[1]);
            } catch (\Throwable) {
                continue;
            }

            $entries[] = [
                'at' => $at,
                'environment' => $m[2],
                'level' => $m[3],
                // The stack trace follows in {"exception":...} — keep the
                // human part, which is what identifies the problem.
                'message' => trim(\Illuminate\Support\Str::limit(
                    preg_replace('/\s*\{"exception".*$/s', '', $m[4]),
                    300
                )),
            ];
        }

        return $entries;
    }

    /** The last $bytes of a file, starting from a whole line. */
    private static function tail(string $file, int $bytes): string
    {
        $size = filesize($file);
        $handle = fopen($file, 'rb');

        if ($handle === false) {
            return '';
        }

        if ($size > $bytes) {
            fseek($handle, -$bytes, SEEK_END);
            fgets($handle);          // discard the partial first line
        }

        $text = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $text;
    }
}
