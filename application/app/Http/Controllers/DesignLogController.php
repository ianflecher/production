<?php

namespace App\Http\Controllers;

use App\Support\DesignLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The designing board the artists' team kept as a spreadsheet.
 *
 * Read-only on purpose. Every column on it is something the system already
 * knows, so the board is a view of the work rather than a second record of it
 * — see App\Support\DesignLog.
 */
class DesignLogController extends Controller
{
    /** How far back the board goes, and the choices offered for that. */
    private const DEFAULT_DAYS = 45;

    private const DAY_CHOICES = [7, 15, 45, 90];

    public function index(Request $request): View
    {
        $days = (int) $request->query('days', self::DEFAULT_DAYS);
        $days = in_array($days, self::DAY_CHOICES, true) ? $days : self::DEFAULT_DAYS;

        $rows = DesignLog::rows($days);

        // Filtered here rather than in the query: the board is one page of
        // work, and asking the database again for each choice of artist would
        // be three more round trips for a list already in hand.
        $artist = trim((string) $request->query('artist', ''));
        $team = strtolower(trim((string) $request->query('team', '')));
        $team = in_array($team, ['meta', 'vip'], true) ? $team : '';

        $artists = $rows->pluck('artist')->filter()->unique()->sort()->values();

        if ($artist !== '') {
            $rows = $rows->filter(fn ($row) => $row['artist'] === $artist);
        }

        if ($team !== '') {
            $rows = $rows->filter(fn ($row) => str_starts_with(strtolower($row['agent']), $team));
        }

        return view('design-log', [
            'days' => $days,
            'dayChoices' => self::DAY_CHOICES,
            'artist' => $artist,
            'artists' => $artists,
            'team' => $team,
            'byDay' => $rows->groupBy(fn ($row) => $row['date']->toDateString()),
            'total' => $rows->count(),
            // The bench, for moving a design from one artist to another
            // without leaving the board. Whether the person reading may do it
            // is the design endpoint's own decision, not this page's.
            'bench' => \App\Models\User::where('is_active', true)
                ->where('job_role', \App\Models\User::JOB_ARTIST)
                ->orderBy('name')
                ->get(['id', 'name']),
            // Counted off the rows already in hand, so the strip is free.
            'tally' => $rows->countBy(fn ($row) => $row['status'])->sortDesc(),
        ]);
    }
}
