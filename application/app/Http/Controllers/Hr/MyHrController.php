<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\HrEmployee;
use App\Models\HrIncident;
use App\Models\HrRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The employee's own side: their payslips, what has been written about them,
 * what they owe, and what they have asked the office for.
 *
 * Everything here is scoped to the signed-in person's own employee record.
 * There is no id in any of these routes for that reason — nothing to change to
 * somebody else's number.
 */
class MyHrController extends Controller
{
    private function me(Request $request): HrEmployee
    {
        $employee = HrEmployee::forUser($request->user());

        // Staff who were here before HR existed have no employee record yet.
        // Better to say so than to show an empty page that looks broken.
        abort_unless($employee, 404);

        return $employee;
    }

    public function index(Request $request): View
    {
        $employee = HrEmployee::forUser($request->user());

        return view('hr.my.index', [
            'employee' => $employee,
            'types' => HrRequest::TYPES,
            'hourly' => HrRequest::HOURLY,
            // What they have left, so they can plan rather than file and hope.
            // Null when nobody has set them an allowance - see LeaveBalance.
            'leave' => $employee ? \App\Support\LeaveBalance::for($employee) : null,
        ]);
    }

    /** File a leave, a change of schedule, undertime, overtime or an OB. */
    public function fileRequest(Request $request): RedirectResponse
    {
        $employee = $this->me($request);

        $data = $request->validate([
            'type' => ['required', 'in:'.implode(',', array_keys(HrRequest::TYPES))],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'starts_at' => ['nullable', 'date_format:H:i'],
            'ends_at' => ['nullable', 'date_format:H:i', 'after:starts_at'],
            'reason' => ['required', 'string', 'max:2000'],
        ], [
            'reason.required' => 'Say why — the office decides on the reason.',
            'ends_on.after_or_equal' => 'The last day cannot be before the first.',
            'ends_at.after' => 'The end time has to be after the start.',
        ]);

        // Only days AWAY from work are counted in days. Overtime, undertime
        // and official business are arrangements about a working day, so they
        // carry no day count and draw down no balance.
        $countsInDays = ! in_array($data['type'], HrRequest::HOURLY, true);

        $workingDays = $countsInDays
            ? \App\Support\Workdays::between(
                $data['starts_on'],
                $data['ends_on'] ?? $data['starts_on']
            )
            : null;

        $hrRequest = $employee->requests()->create([
            'type' => $data['type'],
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'] ?? null,
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'reason' => $data['reason'],
            'status' => HrRequest::STATUS_PENDING,
            // Counted now and kept. Friday to Monday is two days of leave and
            // not four, and over a holiday it may be one - the desk was
            // working that out on paper every time, and nothing on the
            // request said which answer they had used.
            'working_days' => $workingDays,
        ]);

        return back()->with('success', HrRequest::TYPES[$data['type']].' filed. HR will answer it.');
    }

    /** Take back something not yet decided. */
    public function withdrawRequest(Request $request, HrRequest $hrRequest): RedirectResponse
    {
        $employee = $this->me($request);

        abort_unless($hrRequest->hr_employee_id === $employee->id, 403);
        abort_unless($hrRequest->isPending(), 403);

        $hrRequest->delete();

        return back()->with('success', 'Request withdrawn.');
    }

    /** "I have read this." Not agreement — a record that they saw it. */
    public function acknowledgeIncident(Request $request, HrIncident $incident): RedirectResponse
    {
        $employee = $this->me($request);

        abort_unless($incident->hr_employee_id === $employee->id, 403);

        if (! $incident->isAcknowledged()) {
            $incident->update(['acknowledged_at' => now()]);
        }

        return back()->with('success', 'Marked as read.');
    }
}
