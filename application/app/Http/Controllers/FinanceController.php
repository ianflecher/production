<?php

namespace App\Http\Controllers;

use App\Models\Payment;
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

        $payments = $this->filtered($request)->paginate(50)->withQueryString();

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

    /** Download the (filtered) payment ledger as a CSV that opens in Excel. */
    public function export(Request $request)
    {
        $payments = $this->filtered($request)->get();
        $filename = 'payments-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($payments) {
            $out = fopen('php://output', 'w');
            // UTF-8 BOM so Excel reads accents / the peso sign correctly.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Order', 'Client', 'Amount', 'Type', 'Method', 'Reference', 'Proof', 'Recorded by', 'Paid at']);

            foreach ($payments as $p) {
                fputcsv($out, [
                    $p->order?->order_number ?? '',
                    $p->order?->client?->name ?? $p->order?->customer_name ?? '',
                    number_format((float) $p->amount, 2, '.', ''),
                    $p->kind ?? 'payment',
                    $p->method ?? '',
                    $p->reference ?? '',
                    $p->hasProof() ? ($p->proof_name ?: 'yes') : 'none',
                    $p->recorder?->name ?? '',
                    $p->paid_at?->format('Y-m-d H:i') ?? '',
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
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
