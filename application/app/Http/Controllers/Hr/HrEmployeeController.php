<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\Attendance;
use App\Models\HrEmployee;
use App\Models\HrIncident;
use App\Models\HrJobOffer;
use App\Models\HrLoan;
use App\Models\HrPayslip;
use App\Models\HrRequest;
use App\Models\User;
use App\Support\AttendancePay;
use App\Support\LeaveBalance;
use App\Support\PayrollCalculator;
use App\Support\Wage;
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

    /**
     * Take somebody already working here onto the HR books.
     *
     * Hiring through HR creates an employee record as part of accepting the
     * offer, and that was the only way one had ever been made. It is no use to
     * the people who were already here when HR arrived: running them through
     * the pipeline would try to mint a SECOND login, and the email is unique.
     * So every one of them had a login, no employee record, and no My HR page
     * at all - no payslips, no leave, nothing to clock.
     */
    public function create(Request $request): View
    {
        $this->assertAccess($request);

        return view('hr.employees.create', [
            // Only people who have not got one. The table is unique on
            // user_id, so offering the rest would produce nothing but an error.
            'candidates' => User::whereDoesntHave('hrEmployee')
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
            'periods' => HrJobOffer::PERIODS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->assertAccess($request);

        $data = $request->validate([
            // unique: the table enforces one record per login, and a clear
            // message beats a constraint violation.
            'user_id' => ['required', 'integer', 'exists:users,id', 'unique:hr_employees,user_id'],
            'position' => ['nullable', 'string', 'max:120'],
            'salary' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'salary_period' => ['required', 'in:'.implode(',', array_keys(HrJobOffer::PERIODS))],
            'started_on' => ['nullable', 'date'],
            'vacation_credits' => ['nullable', 'integer', 'min:0', 'max:365'],
            'sick_credits' => ['nullable', 'integer', 'min:0', 'max:365'],
        ], [
            'user_id.unique' => 'That person is already on the HR books.',
            'user_id.required' => 'Say who this record is for.',
        ]);

        $employee = HrEmployee::create($data);

        return redirect()->route('hr.employees.show', $employee)
            ->with('success', ($employee->user?->name ?? 'They').' is on the HR books. My HR is open to them now.');
    }

    /**
     * Correct the employment file.
     *
     * Nothing on it could be changed once written. A salary typed wrong at
     * hiring stayed wrong, and the leave allowance - which decides every
     * balance the person is shown - had no way in at all.
     */
    public function edit(Request $request, HrEmployee $employee): View
    {
        $this->assertAccess($request);

        return view('hr.employees.edit', [
            'employee' => $employee->load('user'),
            'periods' => HrJobOffer::PERIODS,
        ]);
    }

    public function update(Request $request, HrEmployee $employee): RedirectResponse
    {
        $this->assertAccess($request);

        $data = $request->validate([
            'position' => ['nullable', 'string', 'max:120'],
            'salary' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'salary_period' => ['required', 'in:'.implode(',', array_keys(HrJobOffer::PERIODS))],
            'started_on' => ['nullable', 'date'],
            'ended_on' => ['nullable', 'date', 'after_or_equal:started_on'],
            // Blank means nobody has set an allowance, which is NOT an
            // allowance of none - see Support\LeaveBalance. Zero is a real
            // answer and means none, so the two must not be collapsed.
            'vacation_credits' => ['nullable', 'integer', 'min:0', 'max:365'],
            'sick_credits' => ['nullable', 'integer', 'min:0', 'max:365'],
        ], [
            'ended_on.after_or_equal' => 'They cannot have left before they started.',
        ]);

        // A box left empty means "not set", so the nulls have to be written
        // rather than dropped - otherwise an allowance could be given and
        // never taken back. Array union keeps what validate() returned and
        // only fills in a field the form did not send at all.
        $employee->update($data + [
            'vacation_credits' => null,
            'sick_credits' => null,
            'ended_on' => null,
        ]);

        return redirect()->route('hr.employees.show', $employee)
            ->with('success', 'Employment file updated.');
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
            'attendance' => ['nullable', 'boolean'],
        ]);

        $typed = HrPayslip::tidyLines($data['deductions'] ?? []);

        // Worked out rather than typed: SSS, PhilHealth, Pag-IBIG and the
        // withholding tax, off this person's monthly salary and shared down
        // to the period this payslip covers.
        // Wage::monthly, not the raw column: the statutory tables are written
        // against a monthly figure, and a daily wage of 600 was being taxed
        // as though it were 600 a month.
        $statutory = $request->boolean('statutory', true)
            ? PayrollCalculator::lines(
                Wage::monthly($employee),
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

        // What the clock says about this period: overtime both approved and
        // worked, and the late and undertime it recorded. Stored and read by
        // nothing until now - a person 419 minutes late got the same wage as
        // one never late.
        $clock = $request->boolean('attendance', true)
            ? AttendancePay::for($employee, $data['period_start'], $data['period_end'])
            : null;

        $payslip = new HrPayslip([
            'hr_employee_id' => $employee->id,
            'period_start' => $data['period_start'],
            'period_end' => $data['period_end'],
            'gross' => round((float) $data['gross'], 2),
            'earnings' => array_merge(
                HrPayslip::tidyLines($data['earnings'] ?? []),
                $clock['earnings'] ?? []
            ),
            'deductions' => array_merge(
                $typed,
                $statutory,
                $clock['deductions'] ?? [],
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

        $said = 'Payslip recorded — net ₱'.number_format((float) $payslip->net, 2).'.';

        // Worked but never asked for. Not paid, and not silently dropped
        // either: the desk may want to approve it and record the slip again.
        if (($clock['overtime_unapproved'] ?? 0) > 0) {
            $said .= ' '.Wage::sayMinutes($clock['overtime_unapproved'])
                .' of overtime was clocked but never approved, so it is not paid.';
        }

        return back()->with('success', $said);
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
            // Named, because the two allowances are separate and the desk
            // needs to know which one it just went past.
            $kind = LeaveBalance::nameOf($hrRequest->type);
            $balance = LeaveBalance::of($hrRequest->employee, $hrRequest->type);

            $said .= ' That is more leave than they had left — '
                .$balance['taken'].' of '.$balance['allowed'].' days of '.$kind.' now used.';
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
