<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\HrApplicant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/** The office side of the applications: who has applied, and what became of them. */
class HrApplicantController extends Controller
{
    private function assertAccess(Request $request): void
    {
        abort_unless($request->user()->canUseHr(), 403);
    }

    public function index(Request $request): View
    {
        $this->assertAccess($request);

        $search = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');

        $applicants = HrApplicant::query()
            ->when($status !== '' && array_key_exists($status, HrApplicant::STATUSES),
                fn ($q) => $q->where('status', $status))
            // The ORs stay in their own group so they cannot break out of the
            // status filter above and show a set-aside applicant on the new list.
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('contact_number', 'like', "%{$search}%")
                ->orWhere('position', 'like', "%{$search}%")))
            ->orderByDesc('applied_at')
            ->orderByDesc('id')
            ->get();

        return view('hr.applicants.index', [
            'applicants' => $applicants,
            'search' => $search,
            'status' => $status,
            'statuses' => HrApplicant::STATUSES,
            'newCount' => HrApplicant::newCount(),
        ]);
    }

    public function show(Request $request, HrApplicant $applicant): View
    {
        $this->assertAccess($request);

        return view('hr.applicants.show', [
            'applicant' => $applicant->load('interviews.interviewer'),
            'statuses' => HrApplicant::STATUSES,
            // Who may sit in on an interview: the desks that do the hiring,
            // plus the leaders and supervisors who interview the people who
            // will work for them. Wider than who may READ these pages, and
            // deliberately so — see User::canInterview.
            'interviewers' => \App\Models\User::where('is_active', true)
                ->get()
                ->filter(fn ($u) => $u->canInterview())
                ->sortBy('name')
                ->values(),
        ]);
    }

    /** The photo, served from private storage to the office only. */
    public function photo(Request $request, HrApplicant $applicant)
    {
        $this->assertAccess($request);

        abort_unless($applicant->photo_path
            && Storage::disk('local')->exists($applicant->photo_path), 404);

        return Storage::disk('local')->response($applicant->photo_path);
    }

    public function setStatus(Request $request, HrApplicant $applicant): RedirectResponse
    {
        $this->assertAccess($request);

        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', array_keys(HrApplicant::STATUSES))],
        ]);

        $applicant->update(['status' => $data['status']]);

        return back()->with('success', $applicant->fullName().' is now '
            .strtolower(HrApplicant::STATUSES[$data['status']]).'.');
    }
}
