<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\HrDeadline;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Dates HR must not miss: payslip cut-offs and government remittances. */
class HrDeadlineController extends Controller
{
    private function assertAccess(Request $request): void
    {
        abort_unless($request->user()->canUseHr(), 403);
    }

    public function index(Request $request): View
    {
        $this->assertAccess($request);

        return view('hr.deadlines.index', [
            'outstanding' => HrDeadline::whereNull('done_at')->orderBy('due_on')->get(),
            'done' => HrDeadline::whereNotNull('done_at')->orderByDesc('due_on')->limit(30)->get(),
            'kinds' => HrDeadline::KINDS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->assertAccess($request);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'kind' => ['required', 'in:'.implode(',', array_keys(HrDeadline::KINDS))],
            'due_on' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        HrDeadline::create($data + ['created_by' => $request->user()->id]);

        return back()->with('success', 'Deadline added.');
    }

    /** Ticking it off, or putting it back if it was ticked by mistake. */
    public function toggle(Request $request, HrDeadline $deadline): RedirectResponse
    {
        $this->assertAccess($request);

        $deadline->update(['done_at' => $deadline->isDone() ? null : now()]);

        return back()->with('success', $deadline->isDone()
            ? $deadline->label.' marked done.'
            : $deadline->label.' put back on the list.');
    }

    public function destroy(Request $request, HrDeadline $deadline): RedirectResponse
    {
        $this->assertAccess($request);

        $deadline->delete();

        return back()->with('success', 'Deadline removed.');
    }
}
