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
        $search = trim((string) $request->query('q', ''));
        $status = trim((string) $request->query('status', ''));
        $mine = $request->boolean('mine');

        $artists = $rows->pluck('artist')->filter()->unique()->sort()->values();

        // Whether to offer the filter at all, answered off the rows already in
        // hand and before any of them are filtered away. A button that can
        // only ever come back empty is a dead button - and asking the database
        // again to find out would cost the page a second copy of itself.
        $me = $request->user()->id;
        $isOnRow = fn ($row) => $row['design']->artist_id === $me
            || $row['design']->inquiry?->created_by === $me;
        $hasOwnRows = $rows->contains($isOnRow);

        if ($artist !== '') {
            $rows = $rows->filter(fn ($row) => $row['artist'] === $artist);
        }

        if ($team !== '') {
            $rows = $rows->filter(fn ($row) => str_starts_with(strtolower($row['agent']), $team));
        }

        // "Mine" has to mean different things to different people, because
        // the board is one page read by two trades: an officer's own rows are
        // the briefs she took, an artist's are the designs on his desk. Asked
        // as one question - am I on this row - it needs no role behind it, and
        // it answers for the account officer who is also a leader, whose rows
        // are hers as an agent and not as a rank.
        if ($mine) {
            $rows = $rows->filter($isOnRow);
        }

        // Ninety days of work is several hundred rows, and finding one client
        // on it meant scrolling for them. The same box every other list has.
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $rows = $rows->filter(fn ($row) => str_contains(mb_strtolower(implode(' ', [
                $row['client'], (string) $row['design']->label, $row['agent'], (string) $row['artist'],
            ])), $needle));
        }

        // Counted before the status filter and not after it, so choosing one
        // status does not zero the others and leave nothing to switch back to.
        $tally = $rows->countBy(fn ($row) => $row['status'])->sortDesc();

        if ($status !== '') {
            $rows = $rows->filter(fn ($row) => $row['status'] === $status);
        }

        return view('design-log', [
            'days' => $days,
            'dayChoices' => self::DAY_CHOICES,
            'artist' => $artist,
            'artists' => $artists,
            'team' => $team,
            'search' => $search,
            'status' => $status,
            'mine' => $mine,
            'hasOwnRows' => $hasOwnRows,
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
            'tally' => $tally,
        ]);
    }
}
