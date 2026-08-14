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

        $rows = $payments->map(function (Payment $p) {
            $gross = (float) $p->amount;
            $vatable = $p->order?->vat_inclusive === true;
            $net = $vatable ? round($gross / (1 + \App\Models\ProductionOrder::VAT_RATE), 2) : $gross;

            return [
                $p->order?->order_number ?? '',
                $p->order?->clientName() ?? '',
                $p->order?->client?->tin ?? '',
                $net,
                $vatable ? round($gross - $net, 2) : 0.0,
                $gross,
                $p->kind ?? 'payment',
                $p->method ?? '',
                $p->reference ?? '',
                $p->hasProof() ? ($p->proof_name ?: 'yes') : 'none',
                $p->recorder?->name ?? '',
                $p->paid_at,
            ];
        });

        return SpreadsheetExport::download(
            'payments-'.now()->format('Y-m-d').'.xlsx',
            'Payment ledger',
            [
                ['Order', SpreadsheetExport::TEXT],
                ['Client', SpreadsheetExport::TEXT],
                ['TIN', SpreadsheetExport::TEXT],
                ['Net of VAT', SpreadsheetExport::MONEY],
                ['VAT', SpreadsheetExport::MONEY],
                ['Amount paid', SpreadsheetExport::MONEY],
                ['Type', SpreadsheetExport::TEXT],
                ['Method', SpreadsheetExport::TEXT],
                ['Reference', SpreadsheetExport::TEXT],
                ['Proof', SpreadsheetExport::TEXT],
                ['Recorded by', SpreadsheetExport::TEXT],
                ['Paid at', SpreadsheetExport::DATE],
            ],
            $rows,
            totalOf: ['Net of VAT', 'VAT', 'Amount paid'],
            subtitle: $payments->count().' payment(s)',
        );
    }

    /** Serve a payment's proof file (finance sees every order's proof). */
    public function proof(Payment $payment)
    {
        abort_unless($payment->hasProof() && Storage::disk('local')->exists($payment->proof_path), 404);

        return Storage::disk('local')->response(
            $payment->proof_path,
            $payment->proof_name ?: basename($payment->proof_path)
        );
    }
}
