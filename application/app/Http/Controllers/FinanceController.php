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

        return Payment::with(['order.client', 'recorder'])
            ->when($search !== '', function ($q) use ($search) {
                $q->whereHas('order', fn ($o) => $o
                    ->where('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%"));
            })
            ->when($method, fn ($q) => $q->where('method', $method))
            ->orderByDesc('paid_at')
            ->orderByDesc('id');
    }

    public function index(Request $request): View
    {
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
        $payments = $this->filtered($request)->get();

        // VATable and non-VAT sales are two different books at filing time, so
        // they get a tab each with their own totals. One list with a VAT column
        // means whoever needs one of them filters and re-adds it by hand every
        // single time — and a hand-made total is a number nobody can check.
        [$vatable, $plain] = $payments->partition(
            fn (Payment $p) => $p->order?->vat_inclusive === true
        );

        return SpreadsheetExport::downloadSheets(
            'payments-'.now()->format('Y-m-d').'.xlsx',
            [
                self::ledgerSheet('VAT sales (12%)', $vatable, true),
                self::ledgerSheet('Non-VAT sales', $plain, false),
                self::summarySheet($vatable, $plain),
            ],
        );
    }

    /**
     * One tab of the ledger.
     *
     * The VAT columns only appear on the VAT tab. Printing "VAT: 0.00" down a
     * whole non-VAT sheet invites somebody to read it as tax that was charged
     * and came to nothing, rather than tax that never applied.
     */
    private static function ledgerSheet(string $title, $payments, bool $withVat): array
    {
        $rows = $payments->map(function (Payment $p) use ($withVat) {
            $gross = (float) $p->amount;
            $net = $withVat
                ? round($gross / (1 + \App\Models\ProductionOrder::VAT_RATE), 2)
                : $gross;

            $line = [
                $p->order?->order_number ?? '',
                $p->order?->clientName() ?? '',
                $p->order?->client?->tin ?? '',
            ];

            if ($withVat) {
                $line[] = $net;
                $line[] = round($gross - $net, 2);
            }

            return array_merge($line, [
                $gross,
                $p->kind ?? 'payment',
                $p->method ?? '',
                $p->reference ?? '',
                $p->hasProof() ? ($p->proof_name ?: 'yes') : 'none',
                $p->recorder?->name ?? '',
                $p->paid_at,
            ]);
        })->values();

        $columns = [
            ['Order', SpreadsheetExport::TEXT],
            ['Client', SpreadsheetExport::TEXT],
            ['TIN', SpreadsheetExport::TEXT],
        ];

        if ($withVat) {
            $columns[] = ['Net of VAT', SpreadsheetExport::MONEY];
            $columns[] = ['VAT 12%', SpreadsheetExport::MONEY];
        }

        $columns = array_merge($columns, [
            ['Amount paid', SpreadsheetExport::MONEY],
            ['Type', SpreadsheetExport::TEXT],
            ['Method', SpreadsheetExport::TEXT],
            ['Reference', SpreadsheetExport::TEXT],
            ['Proof', SpreadsheetExport::TEXT],
            ['Recorded by', SpreadsheetExport::TEXT],
            ['Paid at', SpreadsheetExport::DATE],
        ]);

        return [
            'title' => $title,
            'columns' => $columns,
            'rows' => $rows,
            'totalOf' => $withVat
                ? ['Net of VAT', 'VAT 12%', 'Amount paid']
                : ['Amount paid'],
            'subtitle' => $payments->count().' payment(s)',
        ];
    }

    /** The two tabs added up, so the workbook answers "how much VAT" on its own. */
    private static function summarySheet($vatable, $plain): array
    {
        $vatGross = (float) $vatable->sum('amount');
        $vatNet = round($vatGross / (1 + \App\Models\ProductionOrder::VAT_RATE), 2);
        $plainGross = (float) $plain->sum('amount');

        return [
            'title' => 'Summary',
            'columns' => [
                ['', SpreadsheetExport::TEXT],
                ['Payments', SpreadsheetExport::NUMBER],
                ['Net of VAT', SpreadsheetExport::MONEY],
                ['VAT 12%', SpreadsheetExport::MONEY],
                ['Total collected', SpreadsheetExport::MONEY],
            ],
            'rows' => [
                ['VAT sales (12%)', $vatable->count(), $vatNet, round($vatGross - $vatNet, 2), $vatGross],
                ['Non-VAT sales', $plain->count(), $plainGross, 0.0, $plainGross],
            ],
            'totalOf' => ['Payments', 'Net of VAT', 'VAT 12%', 'Total collected'],
            'subtitle' => 'Both tabs added up',
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
    /** Names used before, so an accountant types theirs once. */
    public static function pastConfirmers(): array
    {
        return Payment::whereNotNull('confirmed_name')
            ->distinct()
            ->orderBy('confirmed_name')
            ->pluck('confirmed_name')
            ->all();
    }

    public function confirm(Request $request, Payment $payment): \Illuminate\Http\RedirectResponse
    {
        abort_unless($request->user()->canConfirmPayments(), 403);

        if ($payment->isConfirmed()) {
            return back()->with('success', 'That payment was already confirmed.');
        }

        // Two accountants share the finance login, so the account cannot say
        // who looked. Asked for, and required: an unsigned confirmation is the
        // thing this whole step exists to prevent.
        $data = $request->validate([
            'confirmed_name' => ['required', 'string', 'max:100'],
        ], [
            'confirmed_name.required' => 'Type your name — the finance login is shared, so the record needs to say who checked.',
        ]);

        $payment->update([
            'confirmed_at' => now(),
            'confirmed_by' => $request->user()->id,
            'confirmed_name' => trim($data['confirmed_name']),
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
        abort_unless($payment->hasProof() && Storage::disk('local')->exists($payment->proof_path), 404);

        return Storage::disk('local')->response(
            $payment->proof_path,
            $payment->proof_name ?: basename($payment->proof_path)
        );
    }
}
