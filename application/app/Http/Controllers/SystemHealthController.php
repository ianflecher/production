<?php

namespace App\Http\Controllers;

use App\Services\ErrorLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Somewhere to see what has been going wrong, without opening the log file. */
class SystemHealthController extends Controller
{
    public function errors(Request $request): View
    {
        $days = (int) $request->query('days', 7);
        $days = in_array($days, [1, 7, 30], true) ? $days : 7;

        // NOT $errors — Blade always shares that name with the validation
        // error bag, so passing our own would be shadowed and blow up.
        $incidents = ErrorLog::recent($days);

        return view('system.errors', [
            'incidents' => $incidents,
            'days' => $days,
            'total' => (int) $incidents->sum('count'),
            'logPath' => ErrorLog::path(),
            'logSize' => is_file(ErrorLog::path()) ? filesize(ErrorLog::path()) : 0,
        ]);
    }

    /**
     * Mark one failure as dealt with, so it stops sitting on the page.
     *
     * The log file is the record and is not edited — this only remembers that
     * somebody looked. If the same thing fails again afterwards it reappears,
     * because the new occurrence is later than the dismissal.
     */
    public function dismiss(Request $request): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'fingerprint' => ['required', 'string', 'size:64'],
        ]);

        \App\Models\DismissedError::updateOrCreate(
            ['fingerprint' => $data['fingerprint']],
            ['dismissed_at' => now(), 'dismissed_by' => $request->user()->id],
        );

        return back()->with('success', 'Cleared. It comes back if it happens again.');
    }

    /**
     * Clear everything currently on the page in one go.
     *
     * After a bad afternoon the list is thirty rows of the same two problems,
     * and clearing them one at a time is thirty clicks that teach nobody
     * anything. Same rule as clearing one: the log is untouched, and anything
     * that happens again comes straight back.
     */
    public function dismissAll(Request $request): \Illuminate\Http\RedirectResponse
    {
        $days = (int) $request->input('days', 7);
        $days = in_array($days, [1, 7, 30], true) ? $days : 7;

        $showing = ErrorLog::recent($days);

        foreach ($showing as $incident) {
            \App\Models\DismissedError::updateOrCreate(
                ['fingerprint' => $incident['fingerprint']],
                ['dismissed_at' => now(), 'dismissed_by' => $request->user()->id],
            );
        }

        return back()->with('success', $showing->count() === 0
            ? 'Nothing to clear.'
            : 'Cleared '.$showing->count().' error'.($showing->count() === 1 ? '' : 's')
                .'. Any of them that happen again will come back.');
    }
}
