<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\Attendance;
use App\Models\HrEmployee;
use App\Models\HrIncident;
use App\Models\HrLoan;
use App\Models\HrPayslip;
use App\Models\HrRequest;
use App\Support\LeaveBalance;
use App\Support\PayrollCalculator;
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
            // The month behind them. HR is asked "is this person habitually
            // late" and until now the only answer was somebody's impression.
            'attendance' => Attendance::where('user_id', $employee->user_id)
                ->whereDate('date', '>=', now()->subDays(29)->toDateString())
                ->orderByDesc('date')
                ->get(),
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
            // Both default to on. Typing SSS and PhilHealth from memory every
            // cutoff is how a wrong keystroke becomes somebody's wage.
            'statutory' => ['nullable', 'boolean'],
            'loans' => ['nullable', 'boolean'],
        ]);

        $typed = HrPayslip::tidyLines($data['deductions'] ?? []);

        // Worked out rather than typed: SSS, PhilHealth, Pag-IBIG and the
        // withholding tax, off this person's monthly salary and shared down
        // to the period this payslip covers.
        $statutory = $request->boolean('statutory', true)
            ? PayrollCalculator::lines(
                (float) $employee->salary,
                $data['period_start'],
                $data['period_end']
            )
            : [];

        // And what they are paying back this cutoff, never more than is left
        // owing. hr_loans has carried a per_payslip figure since the day it
        // was written and nothing has ever applied it.
        $repayments = $request->boolean('loans', true)
            ? $this->loanRepayments($employee)
            : [];

        $payslip = new HrPayslip([
            'hr_employee_id' => $employee->id,
            'period_start' => $data['period_start'],
            'period_end' => $data['period_end'],
            'gross' => round((float) $data['gross'], 2),
            'earnings' => HrPayslip::tidyLines($data['earnings'] ?? []),
            'deductions' => array_merge(
                $typed,
                $statutory,
                array_map(fn ($r) => $r['line'], $repayments)
            ),
            'note' => $data['note'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        $payslip->recomputeNet();

        if ($request->boolean('release')) {
            $payslip->released_at = now();
        }

        $payslip->save();

        // The deduction is only half of a repayment. Without this the loan
        // would be taken off the wage every cutoff for ever and the balance
        // would never move.
        foreach ($repayments as $repayment) {
            $repayment['loan']->payments()->create([
                'amount' => $repayment['line']['amount'],
                'paid_on' => $payslip->period_end,
                'note' => 'From the payslip for '
                    .$payslip->period_start->format('M j').'–'.$payslip->period_end->format('M j, Y'),
                'recorded_by' => $request->user()->id,
            ]);
        }

        if ($payslip->isReleased()) {
            $this->tell($employee, '🧾 Your payslip is ready',
                $payslip->period_start->format('M j').'–'.$payslip->period_end->format('M j'));
        }

        return back()->with('success', 'Payslip recorded — net ₱'.number_format((float) $payslip->net, 2).'.');
    }

    /**
     * What this person is paying back this cutoff, per open loan.
     *
     * Never more than is left owing: a ₱500 instalment against ₱200
     * outstanding takes ₱200 and settles it, rather than taking ₱500 and
     * leaving the shop owing them ₱300 with nothing saying so.
     *
     * A loan with no instalment set is one somebody is paying by hand, so it
     * is left alone.
     *
     * @return array<int, array{loan: HrLoan, line: array{label: string, amount: float}}>
     */
    private function loanRepayments(HrEmployee $employee): array
    {
        $out = [];

        foreach ($employee->loans()->get() as $loan) {
            $instalment = round((float) $loan->per_payslip, 2);
            $left = $loan->balance();

            if ($instalment <= 0 || $left <= 0) {
                continue;
            }

            $take = min($instalment, $left);

            $out[] = [
                'loan' => $loan,
                'line' => [
                    'label' => 'Loan repayment'.($loan->reason ? ' ('.$loan->reason.')' : ''),
                    'amount' => round($take, 2),
                ],
            ];
        }

        return $out;
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
            return back()->withInput()->withErrors(['amount' => 'Only ₱'.number_format($loan->balance(), 2).' is still owed on this loan.']);
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

        $said = $hrRequest->typeLabel().' '.$hrRequest->statusLabel().'.';

        // Said, not refused. Going over an allowance is a decision the desk is
        // allowed to make - somebody with none left may still be let off for a
        // funeral - but it should not be made without being told.
        if ($data['status'] === HrRequest::STATUS_APPROVED
            && LeaveBalance::wouldOverdraw($hrRequest->fresh())) {
            $balance = LeaveBalance::for($hrRequest->employee);

            $said .= ' That is more leave than they had left — '
                .$balance['taken'].' of '.$balance['allowed'].' days now used.';
        }

        return back()->with('success', $said);
    }

    /** Straight to the person it is about, never to a role. */
    private function tell(?HrEmployee $employee, string $title, string $body): void
    {
        if ($employee?->user_id) {
            AppNotification::toUser($employee->user_id, $title, $body, route('hr.my'));
        }
    }
}
