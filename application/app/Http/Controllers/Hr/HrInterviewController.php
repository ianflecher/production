<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\HrApplicant;
use App\Models\HrInterview;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Arranging the interviews, and writing down how they went. */
class HrInterviewController extends Controller
{
    private function assertAccess(Request $request): void
    {
        abort_unless($request->user()->canUseHr(), 403);
    }

    /** Book a round with a named person, and tell them. */
    public function store(Request $request, HrApplicant $applicant): RedirectResponse
    {
        $this->assertAccess($request);

        abort_unless($applicant->canScheduleInterview(), 403);

        $data = $request->validate([
            'interviewer_id' => ['required', 'integer', 'exists:users,id'],
            'scheduled_at' => ['nullable', 'date'],
        ], [
            'interviewer_id.required' => 'Choose who is doing the interview.',
        ]);

        $round = $applicant->nextRound();

        $interview = HrInterview::create([
            'hr_applicant_id' => $applicant->id,
            'round' => $round,
            'interviewer_id' => $data['interviewer_id'],
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'outcome' => HrInterview::OUTCOME_PENDING,
            'created_by' => $request->user()->id,
        ]);

        $applicant->update(['status' => HrApplicant::STATUS_INTERVIEWING]);

        // The interviewer is a named person, so the alert goes to them rather
        // than to a role — a round booked with Boss G is not the supervisor's
        // to do, and telling everyone would mean nobody.
        AppNotification::toUser(
            $interview->interviewer_id,
            '🗓 Interview for you',
            $applicant->fullName().' — round '.$round
                .($interview->scheduled_at ? ' on '.$interview->scheduled_at->format('M j, g:ia') : ''),
            route('hr.applicants.show', $applicant),
        );

        return back()->with('success', 'Round '.$round.' booked with '
            .(User::find($data['interviewer_id'])?->name ?? 'them').'.');
    }

    /** How it went. Passing the third round is as far as this stage goes. */
    public function record(Request $request, HrInterview $interview): RedirectResponse
    {
        $this->assertAccess($request);

        $data = $request->validate([
            'outcome' => ['required', 'in:'.HrInterview::OUTCOME_PASSED.','.HrInterview::OUTCOME_FAILED],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $interview->update([
            'outcome' => $data['outcome'],
            'notes' => $data['notes'] ?? null,
            'completed_at' => now(),
        ]);

        $applicant = $interview->applicant;

        if ($data['outcome'] === HrInterview::OUTCOME_FAILED) {
            $applicant->update(['status' => HrApplicant::STATUS_SET_ASIDE]);

            return back()->with('success', $applicant->fullName().' did not pass round '.$interview->round.'.');
        }

        // Passed. Either there is another round to arrange, or they are through
        // and waiting on the job offer — which is the next stage of this module.
        $through = $applicant->nextRound() === null;

        $applicant->update([
            'status' => $through ? HrApplicant::STATUS_PASSED : HrApplicant::STATUS_INTERVIEWING,
        ]);

        return back()->with('success', $through
            ? $applicant->fullName().' passed every round — ready for a job offer.'
            : $applicant->fullName().' passed round '.$interview->round.'. Book the next one when you are ready.');
    }
}
