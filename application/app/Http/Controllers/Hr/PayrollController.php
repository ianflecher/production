<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\HrEmployee;
use App\Models\HrPayslip;
use App\Support\PayslipDraft;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The cut-off: everybody's payslip for one period, in one go.
 *
 * Every payslip was typed one person at a time - period, period, gross, for
 * thirty-one people, twice a month. The figures were already worked out by
 * then; what was missing was anything that would do the same work for the
 * whole shop at once.
 *
 * Previewed before it is committed. A run writes thirty-one people's wages
 * and there is no undoing that quietly, so the desk sees every line and every
 * net first, and anything the run cannot answer for is listed rather than
 * guessed at.
 */
class PayrollController extends Controller
{
    private function assertAccess(Request $request): void
    {
        abort_unless($request->user()->canUseHr(), 403);
    }

    public function index(Request $request): View
    {
        $this->assertAccess($request);

        [$start, $end] = $this->period($request);

        $drafts = [];
        $skipped = [];

        foreach ($this->onTheBooks() as $employee) {
            $why = $this->cannotPay($employee, $start, $end);

            if ($why !== null) {
                $skipped[] = ['employee' => $employee, 'why' => $why];

                continue;
            }

            $drafts[] = PayslipDraft::for($employee, $start, $end);
        }

        return view('hr.payroll.index', [
            'start' => $start,
            'end' => $end,
            'drafts' => $drafts,
            'skipped' => $skipped,
            'cutoffs' => $this->recentCutoffs(),
            'total' => round(array_sum(array_column($drafts, 'net')), 2),
        ]);
    }

    /**
     * Write the run.
     *
     * The period is re-read from the form and the drafts are rebuilt here
     * rather than carried over from the preview - a preview is a screenshot
     * of a moment, and somebody may have approved an overtime request or
     * clocked a day between then and now. What is written is what is true at
     * the moment of writing, and the count is reported back so a desk that
     * expected thirty-one and got thirty knows to look.
     */
    public function run(Request $request): RedirectResponse
    {
        $this->assertAccess($request);

        $data = $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'release' => ['nullable', 'boolean'],
        ]);

        $start = $data['period_start'];
        $end = $data['period_end'];
        $release = $request->boolean('release');

        $made = 0;
        $paid = 0.0;

        // One transaction: a half-written cut-off is worse than none, because
        // nobody can tell by looking which half ran.
        DB::transaction(function () use ($request, $start, $end, $release, &$made, &$paid) {
            foreach ($this->onTheBooks() as $employee) {
                if ($this->cannotPay($employee, $start, $end) !== null) {
                    continue;
                }

                $draft = PayslipDraft::for($employee, $start, $end);
                $payslip = PayslipDraft::commit($draft, $request->user()->id, $release);

                $made++;
                $paid += (float) $payslip->net;
            }
        });

        if ($made === 0) {
            return back()->with('success', 'Nothing to pay for that period — every payslip already exists, or nobody is payable.');
        }

        $said = $made.' '.($made === 1 ? 'payslip' : 'payslips')
            .' recorded for '.Carbon::parse($start)->format('M j').'–'.Carbon::parse($end)->format('M j, Y')
            .' — ₱'.number_format($paid, 2).' in all.';

        $said .= $release
            ? ' Released to them.'
            : ' Not released yet — they see nothing until you release each one.';

        return redirect()->route('hr.payroll.index', ['start' => $start, 'end' => $end])
            ->with('success', $said);
    }

    /* ---------------- who and when ---------------- */

    /** Everybody still working here, in the order the People page uses. */
    private function onTheBooks()
    {
        return HrEmployee::with(['user', 'loans.payments'])
            ->whereNull('ended_on')
            ->get()
            ->sortBy(fn (HrEmployee $e) => $e->user?->name)
            ->values();
    }

    /**
     * Why this person cannot be paid for this period, or null if they can.
     *
     * Said rather than skipped silently. A person missing from a cut-off is
     * somebody who does not get paid, and the desk must see the reason on the
     * same screen as the run.
     */
    private function cannotPay(HrEmployee $employee, $start, $end): ?string
    {
        if ($employee->salary === null || (float) $employee->salary <= 0) {
            return 'No salary on their file yet.';
        }

        if ($this->alreadyPaid($employee, $start, $end)) {
            return 'Already has a payslip for this period.';
        }

        if ($employee->started_on && $employee->started_on->gt(Carbon::parse($end))) {
            return 'Had not started yet.';
        }

        if ($employee->salary_period === 'daily'
            && PayslipDraft::daysPresent($employee, $start, $end) === 0) {
            return 'Paid daily and the clock has them here on no day of it.';
        }

        return null;
    }

    /** The same period twice would pay somebody twice. */
    private function alreadyPaid(HrEmployee $employee, $start, $end): bool
    {
        return $employee->payslips()
            ->whereDate('period_start', Carbon::parse($start)->toDateString())
            ->whereDate('period_end', Carbon::parse($end)->toDateString())
            ->exists();
    }

    /**
     * The period being worked on.
     *
     * Defaults to the cut-off that today falls in - the 1st to the 15th, or
     * the 16th to the end of the month - because that is the one the desk is
     * nearly always here to run.
     */
    private function period(Request $request): array
    {
        $start = $request->query('start');
        $end = $request->query('end');

        if ($start && $end) {
            return [
                Carbon::parse($start)->toDateString(),
                Carbon::parse($end)->toDateString(),
            ];
        }

        $today = Carbon::today();

        return $today->day <= 15
            ? [$today->copy()->startOfMonth()->toDateString(), $today->copy()->day(15)->toDateString()]
            : [$today->copy()->day(16)->toDateString(), $today->copy()->endOfMonth()->toDateString()];
    }

    /** The last few cut-offs, so the desk can jump to one rather than type dates. */
    private function recentCutoffs(): array
    {
        $out = [];
        $month = Carbon::today()->startOfMonth();

        for ($i = 0; $i < 3; $i++) {
            $out[] = [
                'start' => $month->copy()->day(16)->toDateString(),
                'end' => $month->copy()->endOfMonth()->toDateString(),
            ];
            $out[] = [
                'start' => $month->copy()->startOfMonth()->toDateString(),
                'end' => $month->copy()->day(15)->toDateString(),
            ];

            $month->subMonth();
        }

        return $out;
    }

    /* ---------------- releasing a whole run ---------------- */

    /**
     * Release every payslip in one period at once.
     *
     * Thirty-one separate Release buttons is how a payslip gets forgotten.
     */
    public function release(Request $request): RedirectResponse
    {
        $this->assertAccess($request);

        $data = $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
        ]);

        $slips = HrPayslip::with('employee.user')
            ->whereNull('released_at')
            ->whereDate('period_start', Carbon::parse($data['period_start'])->toDateString())
            ->whereDate('period_end', Carbon::parse($data['period_end'])->toDateString())
            ->get();

        foreach ($slips as $slip) {
            $slip->update(['released_at' => now()]);

            // Straight to the person it is about, never to a role.
            if ($slip->employee?->user_id) {
                AppNotification::toUser(
                    $slip->employee->user_id,
                    '🧾 Your payslip is ready',
                    $slip->period_start->format('M j').'–'.$slip->period_end->format('M j'),
                    route('hr.my'),
                );
            }
        }

        return back()->with('success', $slips->count() === 0
            ? 'Nothing left to release for that period.'
            : $slips->count().' '.($slips->count() === 1 ? 'payslip' : 'payslips').' released.');
    }
}
