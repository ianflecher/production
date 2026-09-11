<?php

namespace App\Support;

use App\Models\HrApplicant;
use App\Models\HrInterview;
use App\Models\User;

/**
 * What the HR desk needs to see first, gathered in one place.
 *
 * It is shown on the dashboard now rather than on a page of its own, so that
 * somebody who does HR sees the hiring the moment they sign in. Kept here
 * rather than in the dashboard controller because it is asked for from more
 * than one screen, and two copies of these queries would drift apart.
 */
class HrOverview
{
    /** @return array<string, mixed>|null  null when this person has no HR to do */
    public static function for(?User $user): ?array
    {
        if (! $user || ! $user->canUseHr()) {
            return null;
        }

        return [
            // Nobody has looked at these yet.
            'newApplicants' => HrApplicant::where('status', HrApplicant::STATUS_NEW)
                ->orderByDesc('applied_at')
                ->get(),

            // Booked and not done — everybody's, so a round nobody has done
            // can be chased. Undated first: those are the ones going cold.
            'upcoming' => HrInterview::with(['applicant', 'interviewer'])
                ->where('outcome', HrInterview::OUTCOME_PENDING)
                ->orderByRaw('scheduled_at is null desc')
                ->orderBy('scheduled_at')
                ->get(),

            // This person's own, called out separately: a supervisor opening
            // the page is looking for their own name, not the shop's hiring.
            'mine' => HrInterview::pendingFor($user),

            // Through every round, waiting on an offer.
            'passed' => HrApplicant::where('status', HrApplicant::STATUS_PASSED)
                ->orderByDesc('updated_at')
                ->get(),

            'interviewing' => HrApplicant::where('status', HrApplicant::STATUS_INTERVIEWING)->count(),

            // Filed and not answered. These hold somebody's day up, so they
            // sit with the hiring rather than behind another click.
            'requests' => \App\Models\HrRequest::with('employee.user')
                ->where('status', \App\Models\HrRequest::STATUS_PENDING)
                ->orderBy('starts_on')
                ->get(),

            // Due or overdue only — a deadline three weeks out is not news.
            'deadlines' => \App\Models\HrDeadline::whereNull('done_at')
                ->whereDate('due_on', '<=', now()->addDays(7))
                ->orderBy('due_on')
                ->get(),
        ];
    }
}
