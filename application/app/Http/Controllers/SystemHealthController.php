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
}
