<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\PettyCashTopup;
use App\Services\SpreadsheetExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * The expense book: everything the shop paid for, month by month.
 *
 * Money coming IN is a separate question and lives with FinanceController,
 * which is the payment ledger. This page used to show both and a profit
 * figure off the difference, which made it look like a profit statement it
 * was never actually keeping - the two halves are reconciled against
 * different statements and read by different people.
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

        $expenseTotal = Expense::totalBetween($from, $to);

        // Searched across everything the bookkeeper might remember about a
        // row - the supplier, the invoice number, the account title, who
        // ordered it - because "find the Divisoria fabric one" is how the
        // question actually arrives, and only one of those is the description.
        $search = trim((string) $request->query('q', ''));

        $expenses = Expense::with('recorder')
            ->whereBetween('spent_at', [$from, $to])
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.$search.'%';

                $q->where(fn ($w) => $w
                    ->where('description', 'like', $like)
                    ->orWhere('account_title', 'like', $like)
                    ->orWhere('supplier', 'like', $like)
                    ->orWhere('reference', 'like', $like)
                    ->orWhere('si_cr_no', 'like', $like)
                    ->orWhere('ordered_by', 'like', $like)
                    ->orWhere('tin', 'like', $like)
                    ->orWhere('method', 'like', $like)
                    ->orWhere('note', 'like', $like));
            })
            ->orderByDesc('spent_at')
            ->orderByDesc('id')
            ->get();

        // Where the money went, biggest first. Grouped by the account title
        // now, which is the line the books are kept in.
        $byCategory = $expenses
            ->groupBy('account_title')
            ->map(fn ($rows) => (float) $rows->sum('amount'))
            ->sortDesc();

        // And the same money rolled up to the four groups, which is the
        // shape the bookkeeper reads a month in.
        $byGroup = $expenses
            ->groupBy(fn (Expense $e) => Expense::groupOf($e->account_title) ?? 'Unfiled')
            ->map(fn ($rows) => (float) $rows->sum('amount'))
            ->sortDesc();

        return view('finance.books', [
            'month' => $month,
            'monthValue' => $month->format('Y-m'),
            'expenseTotal' => $expenseTotal,
            'expenses' => $expenses,
            'byCategory' => $byCategory,
            'byGroup' => $byGroup,
            'search' => $search,
            'accountTitles' => Expense::ACCOUNT_TITLES,
            'referenceTypes' => Expense::REFERENCE_TYPES,
            'vatStatuses' => Expense::VAT_STATUSES,
            'methods' => Expense::METHODS,
            // The tin is a running balance, not a monthly one: money left in
            // it on the 31st is still in it on the 1st. So it is deliberately
            // NOT filtered by the month being looked at, while the top-ups
            // listed beside it are, like everything else on this page.
            'pettyCash' => PettyCashTopup::balance(),
            'pettyCashIn' => PettyCashTopup::totalIn(),
            'pettyCashOut' => PettyCashTopup::totalOut(),
            'pettyCashTopups' => PettyCashTopup::with('recorder')
                ->whereBetween('occurred_at', [$from, $to])
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    /** Put money into the petty cash tin. */
    public function topUpPettyCash(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:100000000'],
            'occurred_at' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        PettyCashTopup::create([
            'amount' => round((float) $data['amount'], 2),
            'occurred_at' => $data['occurred_at'],
            'note' => $data['note'] ?? null,
            'recorded_by' => $request->user()->id,
        ]);

        return redirect()
            ->route('books.index', ['month' => Carbon::parse($data['occurred_at'])->format('Y-m')])
            ->with('success', '₱'.number_format((float) $data['amount'], 2).' added to petty cash. '
                .'The tin now holds ₱'.number_format(PettyCashTopup::balance(), 2).'.');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            // The three the books cannot do without.
            'account_title' => ['required', 'in:'.implode(',', Expense::accountTitles())],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:100000000'],
            'spent_at' => ['required', 'date'],

            // Paid on a different day from ordered, often weeks later, and
            // never before it was ordered.
            'paid_at' => ['nullable', 'date', 'after_or_equal:spent_at'],

            'ordered_by' => ['nullable', 'string', 'max:120'],
            'method' => ['nullable', 'in:'.implode(',', Expense::METHODS)],
            'reference_type' => ['nullable', 'in:'.implode(',', Expense::REFERENCE_TYPES)],
            'reference' => ['nullable', 'string', 'max:255'],
            'si_cr_no' => ['nullable', 'string', 'max:255'],
            'supplier' => ['nullable', 'string', 'max:255'],
            'tin' => ['nullable', 'string', 'max:40'],
            'business_address' => ['nullable', 'string', 'max:255'],
            'vat_status' => ['nullable', 'in:'.implode(',', Expense::VAT_STATUSES)],
            'note' => ['nullable', 'string', 'max:2000'],
            'receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:512000'],
        ], [
            'account_title.required' => 'Pick the account title this belongs to.',
            'account_title.in' => 'That is not one of the shop account titles.',
            'paid_at.after_or_equal' => 'It cannot have been paid before it was ordered.',
        ]);

        // A tin cannot pay out more than it holds. Without this the balance
        // goes negative and stops meaning anything - the point of counting a
        // tin is that the number matches the notes inside it.
        if (($data['method'] ?? null) === Expense::METHOD_PETTY_CASH) {
            $balance = PettyCashTopup::balance();

            if (round((float) $data['amount'], 2) > $balance) {
                return back()->withInput()->withErrors(['amount' => 'Petty cash only holds ₱'.number_format($balance, 2)
                    .'. Add money to the tin first, or pay this one another way.']);
            }
        }

        // The form shows the type inside the reference box - pick PO and it
        // reads "PO-0042" - but the two are stored apart so the column can be
        // sorted. Strip the prefix back off here rather than in the script,
        // because a reference typed by hand, pasted, or sent with the script
        // disabled must come out the same way. Without this the export joins
        // the type on a second time and reads PO-PO-0042.
        if (filled($data['reference'] ?? null) && filled($data['reference_type'] ?? null)) {
            $prefix = $data['reference_type'].'-';

            if (str_starts_with(strtoupper($data['reference']), strtoupper($prefix))) {
                $data['reference'] = substr($data['reference'], strlen($prefix));
            }
        }

        // A type and nothing else is not a reference.
        if (blank($data['reference'] ?? null)) {
            $data['reference'] = null;
        }

        $receiptPath = null;
        $receiptName = null;
        if ($request->hasFile('receipt')) {
            $file = $request->file('receipt');
            $receiptName = $file->getClientOriginalName();
            $receiptPath = $file->store('expense-receipts', 'local');
        }

        Expense::create([
            'account_title' => $data['account_title'],
            'description' => $data['description'],
            'amount' => round((float) $data['amount'], 2),
            'spent_at' => $data['spent_at'],
            'paid_at' => $data['paid_at'] ?? null,
            'ordered_by' => $data['ordered_by'] ?? null,
            'method' => $data['method'] ?? null,
            'reference_type' => $data['reference_type'] ?? null,
            'reference' => $data['reference'] ?? null,
            'si_cr_no' => $data['si_cr_no'] ?? null,
            'supplier' => $data['supplier'] ?? null,
            'tin' => $data['tin'] ?? null,
            'business_address' => $data['business_address'] ?? null,
            'vat_status' => $data['vat_status'] ?? null,
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
    /**
     * The month, in the shape the bookkeeper reads.
     *
     * Column for column and in the order of the sheet finance already works
     * from, so a month can be pasted straight in rather than rearranged by
     * hand every time. The search carries through: what is on the screen is
     * what comes out of the file.
     */
    public function export(Request $request)
    {
        $month = $this->month($request);
        $search = trim((string) $request->query('q', ''));

        $expenses = Expense::with('recorder')
            ->whereBetween('spent_at', [$month->toDateString(), $month->copy()->endOfMonth()->toDateString()])
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.$search.'%';

                $q->where(fn ($w) => $w
                    ->where('description', 'like', $like)
                    ->orWhere('account_title', 'like', $like)
                    ->orWhere('supplier', 'like', $like)
                    ->orWhere('reference', 'like', $like)
                    ->orWhere('si_cr_no', 'like', $like)
                    ->orWhere('ordered_by', 'like', $like)
                    ->orWhere('tin', 'like', $like)
                    ->orWhere('method', 'like', $like)
                    ->orWhere('note', 'like', $like));
            })
            ->orderBy('spent_at')
            ->get();

        $rows = $expenses->map(fn (Expense $e) => [
            $e->spent_at,
            $e->ordered_by ?? '',
            // "PO-0042" rather than two columns: the reference reads as one
            // thing on paper, and it is stored as two so it can be sorted.
            trim(($e->reference_type ? $e->reference_type.'-' : '').($e->reference ?? ''), '-'),
            $e->paid_at,
            $e->si_cr_no ?? '',
            $e->tin ?? '',
            $e->business_address ?? '',
            $e->supplier ?? '',
            $e->description,
            $e->account_title ?? '',
            (float) $e->amount,
            $e->method ?? '',
            $e->vat_status ?? '',
            $e->recorder?->name ?? '',
        ]);

        return SpreadsheetExport::download(
            'expenses-'.$month->format('Y-m').'.xlsx',
            'Expenses '.$month->format('F Y'),
            [
                ['Order Date', SpreadsheetExport::DATE],
                ['Ordered By', SpreadsheetExport::TEXT],
                ['Reference', SpreadsheetExport::TEXT],
                ['Date Paid', SpreadsheetExport::DATE],
                ['SI/CR No.', SpreadsheetExport::TEXT],
                ['TIN', SpreadsheetExport::TEXT],
                ['Busines Address', SpreadsheetExport::TEXT],
                ['Supplier/Vendor', SpreadsheetExport::TEXT],
                ['Description', SpreadsheetExport::TEXT],
                ['Account Titles', SpreadsheetExport::TEXT],
                ['Amount', SpreadsheetExport::MONEY],
                ['Payment Method', SpreadsheetExport::TEXT],
                ['VAT/N-VAT', SpreadsheetExport::TEXT],
                ['Recorded by', SpreadsheetExport::TEXT],
            ],
            $rows,
            totalOf: ['Amount'],
            subtitle: $expenses->count().' expense(s)'
                .($search !== '' ? ' matching "'.$search.'"' : ''),
        );
    }
}
