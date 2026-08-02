<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * The books: money in (payments) against money out (expenses), month by month.
 * FinanceController stays the payment ledger; this is the profit picture and
 * the only place expenses are recorded.
 */
class BookkeepingController extends Controller
{
    /** The month being viewed, as a Carbon on its first day. */
    private function month(Request $request): Carbon
    {
        $raw = (string) $request->query('month', '');

        // Accept YYYY-MM from the month picker; anything else = this month.
        if (preg_match('/^\d{4}-\d{2}$/', $raw)) {
            try {
                return Carbon::createFromFormat('Y-m-d', $raw.'-01')->startOfMonth();
            } catch (\Throwable) {
                // fall through
            }
        }

        return now()->startOfMonth();
    }

    public function index(Request $request): View
    {
        $month = $this->month($request);
        $from = $month->toDateString();
        $to = $month->copy()->endOfMonth()->toDateString();

        // Money in: payments are timestamped, so compare on the date part.
        $income = (float) Payment::whereDate('paid_at', '>=', $from)
            ->whereDate('paid_at', '<=', $to)
            ->sum('amount');

        $expenseTotal = Expense::totalBetween($from, $to);

        $expenses = Expense::with('recorder')
            ->whereBetween('spent_at', [$from, $to])
            ->orderByDesc('spent_at')
            ->orderByDesc('id')
            ->get();

        // Where the money went, biggest bucket first.
        $byCategory = $expenses
            ->groupBy('category')
            ->map(fn ($rows) => (float) $rows->sum('amount'))
            ->sortDesc();

        return view('finance.books', [
            'month' => $month,
            'monthValue' => $month->format('Y-m'),
            'income' => $income,
            'expenseTotal' => $expenseTotal,
            'profit' => $income - $expenseTotal,
            'expenses' => $expenses,
            'byCategory' => $byCategory,
            'categories' => Expense::CATEGORIES,
            'methods' => Expense::METHODS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'category' => ['required', 'in:'.implode(',', array_keys(Expense::CATEGORIES))],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:100000000'],
            'spent_at' => ['required', 'date'],
            'method' => ['nullable', 'in:'.implode(',', Expense::METHODS)],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
            // Receipts are optional here — unlike a client payment, not every
            // shop expense comes with one.
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:65536'],
        ]);

        $receiptPath = null;
        $receiptName = null;
        if ($request->hasFile('receipt')) {
            $file = $request->file('receipt');
            $receiptName = $file->getClientOriginalName();
            $receiptPath = $file->store('expense-receipts', 'local');
        }

        Expense::create([
            'category' => $data['category'],
            'description' => $data['description'],
            'amount' => round((float) $data['amount'], 2),
            'spent_at' => $data['spent_at'],
            'method' => $data['method'] ?? null,
            'reference' => $data['reference'] ?? null,
            'note' => $data['note'] ?? null,
            'receipt_path' => $receiptPath,
            'receipt_name' => $receiptName,
            'recorded_by' => $request->user()->id,
        ]);

        return redirect()
            ->route('books.index', ['month' => Carbon::parse($data['spent_at'])->format('Y-m')])
            ->with('success', 'Expense recorded (₱'.number_format((float) $data['amount'], 2).').');
    }

    public function destroy(Request $request, Expense $expense): RedirectResponse
    {
        $month = $expense->spent_at?->format('Y-m');
        $expense->delete(); // soft delete — the receipt file is kept

        return redirect()
            ->route('books.index', ['month' => $month])
            ->with('success', 'Expense removed.');
    }

    /** Serve an expense receipt (finance desk only, private disk). */
    public function receipt(Expense $expense)
    {
        abort_unless($expense->hasReceipt() && Storage::disk('local')->exists($expense->receipt_path), 404);

        return Storage::disk('local')->response(
            $expense->receipt_path,
            $expense->receipt_name ?: basename($expense->receipt_path)
        );
    }

    /** The month's expenses as a CSV for the accountant. */
    public function export(Request $request)
    {
        $month = $this->month($request);
        $expenses = Expense::with('recorder')
            ->whereBetween('spent_at', [$month->toDateString(), $month->copy()->endOfMonth()->toDateString()])
            ->orderBy('spent_at')
            ->get();

        $filename = 'expenses-'.$month->format('Y-m').'.csv';

        return response()->streamDownload(function () use ($expenses) {
            $out = fopen('php://output', 'w');
            // UTF-8 BOM so Excel reads the peso sign correctly.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Date', 'Category', 'Description', 'Amount', 'Method', 'Reference', 'Receipt', 'Recorded by']);

            foreach ($expenses as $e) {
                fputcsv($out, [
                    $e->spent_at?->format('Y-m-d') ?? '',
                    $e->categoryLabel(),
                    $e->description,
                    number_format((float) $e->amount, 2, '.', ''),
                    $e->method ?? '',
                    $e->reference ?? '',
                    $e->hasReceipt() ? ($e->receipt_name ?: 'yes') : 'none',
                    $e->recorder?->name ?? '',
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
