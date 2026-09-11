<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\SpreadsheetExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Finance desk: every payment across every order, with proof — read-only.
 */
class FinanceController extends Controller
{
    /** Payments query with the page's search / method filters applied. */
    private function filtered(Request $request)
    {
        $search = trim((string) $request->query('q', ''));
        $method = $request->query('method');

        return Payment::with(['order.client', 'recorder', 'confirmer'])
            ->when($search !== '', function ($q) use ($search) {
                $q->whereHas('order', fn ($o) => $o
                    ->where('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%"));
            })
            ->when($method, fn ($q) => $method === Payment::METHOD_OTHER_TRANSFER
                ? $q->where('method', 'like', Payment::METHOD_OTHER_TRANSFER.'%')
                : $q->where('method', $method))
            ->orderByDesc('paid_at')
            ->orderByDesc('id');
    }

    public function index(Request $request): View
    {
        abort_unless($request->user()->canManageFinance(), 403);
        $search = trim((string) $request->query('q', ''));
        $method = $request->query('method');

        $payments = $this->filtered($request)->paginate(self::PER_PAGE)->withQueryString();

        // Totals span ALL payments, not just the current page/filter.
        $totalCollected = (float) Payment::sum('amount');
        $paymentCount = Payment::count();
        $thisMonth = (float) Payment::whereMonth('paid_at', now()->month)
            ->whereYear('paid_at', now()->year)
            ->sum('amount');

        return view('finance.index', compact(
            'payments', 'totalCollected', 'paymentCount', 'thisMonth', 'search', 'method'
        ));
    }

    /**
     * The (filtered) payment ledger as a real Excel file.
     *
     * VAT is broken out per line. The order carries the VAT flag and the shop
     * is billed 12% on top, so a payment against a VAT order is itself part
     * net and part tax — a ledger that only shows the gross cannot be checked
     * against anything, which is most of the point of exporting it.
     */
    public function export(Request $request)
    {
        abort_unless($request->user()->canManageFinance(), 403);

        // This is the actual cash ledger, not a queue of claims awaiting the
        // bank check. Pending entries stay on Finance until confirmed.
        $payments = $this->filtered($request)->whereNotNull('confirmed_at')->get();

        return SpreadsheetExport::download(
            'payments-'.now()->format('Y-m-d').'.xlsx',
            'Confirmed payments',
            self::ledgerColumns(),
            self::ledgerRows($payments),
            ['Amount paid'],
            $payments->count().' confirmed payment(s)',
        );
    }

    /**
     * One tab of the ledger.
     *
     * The VAT columns only appear on the VAT tab. Printing "VAT: 0.00" down a
     * whole non-VAT sheet invites somebody to read it as tax that was charged
     * and came to nothing, rather than tax that never applied.
     */
    private static function ledgerRows($payments): array
    {
        return $payments->map(function (Payment $p) {
            return [
                $p->paid_at,
                $p->order?->order_number ?? '',
                $p->order?->clientName() ?? '',
                $p->order?->client?->tin ?? '',
                (float) $p->amount,
                $p->method ?? '',
                $p->reference ?? '',
                $p->kind ?? 'payment',
                $p->recorder?->name ?? '',
                $p->confirmedByName() ?? '',
            ];
        })->values()->all();
    }

    private static function ledgerColumns(): array
    {
        return [
            ['Date', SpreadsheetExport::DATE],
            ['Order #', SpreadsheetExport::TEXT],
            ['Name', SpreadsheetExport::TEXT],
            ['TIN', SpreadsheetExport::TEXT],
            ['Amount paid', SpreadsheetExport::MONEY],
            ['Method', SpreadsheetExport::TEXT],
            ['Reference number', SpreadsheetExport::TEXT],
            ['Type', SpreadsheetExport::TEXT],
            ['Recorded by', SpreadsheetExport::TEXT],
            ['Confirmed by', SpreadsheetExport::TEXT],
        ];
    }

    /** Serve a payment's proof file (finance sees every order's proof). */
    /**
     * Finance says the money landed.
     *
     * What the officer recorded is what the client told them. This is the desk
     * that watches the account agreeing — and it is what starts the job: the
     * mockup is released and the tech pack opens off the back of it.
     */
    public function confirm(Request $request, Payment $payment): \Illuminate\Http\RedirectResponse
    {
        abort_unless($request->user()->canConfirmPayments(), 403);

        if ($payment->isConfirmed()) {
            return back()->with('success', 'That payment was already confirmed.');
        }

        // The signed-in Finance account is the confirmation record. This keeps
        // the audit trail reliable without asking staff to type a second name.
        $payment->update([
            'confirmed_at' => now(),
            'confirmed_by' => $request->user()->id,
            'confirmed_name' => $request->user()->name,
        ]);

        // Confirming the FIRST payment is what opens the job. Asked again now
        // the answer has changed: hasDownpayment() counts confirmed money, so
        // before this update it was false and now it is true.
        $order = $payment->order?->fresh();

        if ($order && $order->hasDownpayment()) {
            $order->unlockStage(\App\Models\ProductionOrder::STAGE_MOCKUP);

            // The clock starts here too: every step gets its share of the time
            // between now and the due date.
            $order->scheduleStepDeadlines();

            // And the sample gets a date of its own - three days from this
            // payment. The order's due date is the promise
            // to the client about the finished batch; this is the shop's
            // promise about the sample, and a sample that quietly sat for a
            // week used to surface only when the batch behind it ran short.
            $order->applySampleDueDate();
        }

        return back()->with('success', sprintf(
            'Payment of ₱%s on %s confirmed by %s.',
            number_format((float) $payment->amount, 2),
            $order?->order_number ?? 'the order',
            $payment->confirmedByName()
        ));
    }

    public function proof(Payment $payment)
    {
        abort_unless(request()->user()?->canManageFinance(), 403);
        abort_unless($payment->hasProof() && Storage::disk('local')->exists($payment->proof_path), 404);

        return Storage::disk('local')->response(
            $payment->proof_path,
            $payment->proof_name ?: basename($payment->proof_path)
        );
    }
}
