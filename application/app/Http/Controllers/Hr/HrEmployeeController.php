<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\HrEmployee;
use App\Models\HrIncident;
use App\Models\HrLoan;
use App\Models\HrPayslip;
use App\Models\HrRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The office side of a person: what they are paid, owed, and answerable for. */
class HrEmployeeController extends Controller
{
    private function assertAccess(Request $request): void
    {
        abort_unless($request->user()->canUseHr(), 403);
    }

    public function index(Request $request): View
    {
        $this->assertAccess($request);

        $search = trim((string) $request->query('q', ''));

        $employees = HrEmployee::with(['user', 'loans.payments'])
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('position', 'like', "%{$search}%")
                ->orWhereHas('user', fn ($u) => $u
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%"))))
            ->get()
            ->sortBy(fn ($e) => $e->user?->name)
            ->values();

        return view('hr.employees.index', [
            'employees' => $employees,
            'search' => $search,
        ]);
    }

    public function show(Request $request, HrEmployee $employee): View
    {
        $this->assertAccess($request);

        return view('hr.employees.show', [
            'employee' => $employee->load(['user', 'payslips', 'incidents', 'loans.payments', 'requests']),
            'kinds' => HrIncident::KINDS,
        ]);
    }

    // ---- Payslips -------------------------------------------------------

    public function storePayslip(Request $request, HrEmployee $employee): RedirectResponse
    {
        $this->assertAccess($request);

        $data = $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'gross' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'earnings' => ['nullable', 'array', 'max:20'],
            'earnings.*.label' => ['nullable', 'string', 'max:60'],
            'earnings.*.amount' => ['nullable', 'numeric', 'min:-100000000', 'max:100000000'],
            'deductions' => ['nullable', 'array', 'max:20'],
            'deductions.*.label' => ['nullable', 'string', 'max:60'],
            'deductions.*.amount' => ['nullable', 'numeric', 'min:-100000000', 'max:100000000'],
            'note' => ['nullable', 'string', 'max:2000'],
            'release' => ['nullable', 'boolean'],
        ]);

        $payslip = new HrPayslip([
            'hr_employee_id' => $employee->id,
            'period_start' => $data['period_start'],
            'period_end' => $data['period_end'],
            'gross' => round((float) $data['gross'], 2),
            'earnings' => HrPayslip::tidyLines($data['earnings'] ?? []),
            'deductions' => HrPayslip::tidyLines($data['deductions'] ?? []),
            'note' => $data['note'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        $payslip->recomputeNet();

        if ($request->boolean('release')) {
            $payslip->released_at = now();
        }

        $payslip->save();

        if ($payslip->isReleased()) {
            $this->tell($employee, '🧾 Your payslip is ready',
                $payslip->period_start->format('M j').'–'.$payslip->period_end->format('M j'));
        }

        return back()->with('success', 'Payslip recorded — net ₱'.number_format((float) $payslip->net, 2).'.');
    }

    /** Let them see it. Until this, a half-typed payslip stays in the office. */
    public function releasePayslip(Request $request, HrPayslip $payslip): RedirectResponse
    {
        $this->assertAccess($request);

        if (! $payslip->isReleased()) {
            $payslip->update(['released_at' => now()]);

            $this->tell($payslip->employee, '🧾 Your payslip is ready',
                $payslip->period_start->format('M j').'–'.$payslip->period_end->format('M j'));
        }

        return back()->with('success', 'Payslip released.');
    }

    // ---- Incidents ------------------------------------------------------

    public function storeIncident(Request $request, HrEmployee $employee): RedirectResponse
    {
        $this->assertAccess($request);

        $data = $request->validate([
            'occurred_on' => ['required', 'date'],
            'kind' => ['required', 'in:'.implode(',', array_keys(HrIncident::KINDS))],
            'description' => ['required', 'string', 'max:2000'],
            'action_taken' => ['nullable', 'string', 'max:2000'],
        ]);

        $employee->incidents()->create($data + ['created_by' => $request->user()->id]);

        $this->tell($employee, '📋 Something was recorded about you',
            HrIncident::KINDS[$data['kind']].' — open your account to read it.');

        return back()->with('success', 'Recorded.');
    }

    // ---- Loans ----------------------------------------------------------

    public function storeLoan(Request $request, HrEmployee $employee): RedirectResponse
    {
        $this->assertAccess($request);

        $data = $request->validate([
            'principal' => ['required', 'numeric', 'min:0.01', 'max:100000000'],
            'reason' => ['nullable', 'string', 'max:255'],
            'borrowed_on' => ['required', 'date'],
            'per_payslip' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $employee->loans()->create($data + ['created_by' => $request->user()->id]);

        return back()->with('success', 'Loan recorded.');
    }

    public function storeLoanPayment(Request $request, HrLoan $loan): RedirectResponse
    {
        $this->assertAccess($request);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:100000000'],
            'paid_on' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        // A loan cannot be paid back further than it was ever worth. The
        // balance is a sum, so an over-payment would just read as nothing
        // owing and the extra would vanish without anybody noticing.
        if (round((float) $data['amount'], 2) > $loan->balance()) {
            return back()->withInput()->withErrors(['amount' =>
                'Only ₱'.number_format($loan->balance(), 2).' is still owed on this loan.']);
        }

        $loan->payments()->create($data + ['recorded_by' => $request->user()->id]);

        return back()->with('success', 'Payment recorded — ₱'
            .number_format($loan->fresh()->balance(), 2).' still owed.');
    }

    // ---- Requests -------------------------------------------------------

    public function decideRequest(Request $request, HrRequest $hrRequest): RedirectResponse
    {
        $this->assertAccess($request);
        abort_unless($hrRequest->isPending(), 403);

        $data = $request->validate([
            'status' => ['required', 'in:'.HrRequest::STATUS_APPROVED.','.HrRequest::STATUS_DECLINED],
            'decision_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $hrRequest->update([
            'status' => $data['status'],
            'decision_note' => $data['decision_note'] ?? null,
            'decided_by' => $request->user()->id,
            'decided_at' => now(),
        ]);

        $this->tell($hrRequest->employee,
            $data['status'] === HrRequest::STATUS_APPROVED ? '✅ Request approved' : '✖ Request declined',
            $hrRequest->typeLabel().' — '.$hrRequest->whenLabel());

        return back()->with('success', $hrRequest->typeLabel().' '.$hrRequest->statusLabel().'.');
    }

    /** Straight to the person it is about, never to a role. */
    private function tell(?HrEmployee $employee, string $title, string $body): void
    {
        if ($employee?->user_id) {
            AppNotification::toUser($employee->user_id, $title, $body, route('hr.my'));
        }
    }
}
