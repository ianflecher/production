<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Money going OUT of the business — the other half of the books from Payment,
 * which is money coming in. Recorded by the finance desk.
 */
class Expense extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The shop's chart of accounts, exactly as the bookkeeper keeps it.
     *
     * Seventy-eight titles in four groups, taken from the sheet finance works
     * from. What was here before was ten homemade buckets - "Raw materials",
     * "Utilities" - which do not map onto this at all: COS - Fabric and
     * COS - Ink are both raw materials and are separate lines in the books.
     *
     * The titles are the VALUES, not keys. They are what appears in the
     * export and what the bookkeeper reconciles against, so a key would only
     * be a second name for the same thing and a chance for the two to drift.
     *
     * Spelling is theirs throughout, including where it differs from the
     * dictionary - these strings are matched against their own workbook.
     */
    public const ACCOUNT_TITLES = [
        'Cost of Sales (COS)' => [
            'COS - Caps',
            'COS - Direct Supplies/Materials',
            'COS - Fabric',
            'COS - Flag',
            'COS - IC Store(Consignment)',
            'COS - Ink',
            'COS - Jacket/Hoodies/Sweater',
            'COS - Packaging Supplies',
            'COS - Pants',
            'COS - Polo Shirt',
            'COS - Printing Supplies',
            'COS - Sewing Supplies',
            'COS - Shirt',
            'COS - Silkscreen',
            'COS - Sticker',
            'COS - Sub - Con',
            'COS - Sublimation Paper',
            'COS - Seasonal Payroll',
            'COS - Tents',
            'COS - Umbrella',
        ],
        'Employee Related Expenses (ERE)' => [
            'ERE - Salaries and Wages (Basic)',
            "ERE - Employee's Allowances",
            'ERE - 13th Month Pay 2026',
            'ERE - Year-End Bonus 2026',
            "ERE - Other Employee's Benefits",
            'ERE - SSS',
            'ERE - Pag-Ibig',
            'ERE - PhilHealth',
        ],
        'Admin Expenses (AE)' => [
            'AE - Bank Charges',
            'AE - BFP Expense',
            'AE - BIR (Form 2550Q - VAT)',
            'AE - BIR (Form 1702Q - ITR)',
            'AE - BIR (Form 0619E - ExpandedWT)',
            'AE - BIR (Form 1601C - WTCompen)',
            'AE - BIR (Juan Tax)',
            'AE - Building Insurance',
            'AE - Building Inspection Fee (Annually)',
            'AE - Permits and Licenses',
            'AE - Preparation & Filing of AFS/ITR',
            'AE - Retainers Fee/Professional Fee',
            'AE - Other Admin Expenses',
        ],
        'Non-Employee Related Expenses (NERE)' => [
            'Building Improvements - Const. Materials',
            'Building Improvements - Const. Payroll',
            'Courier Fees/Shipping Fee',
            'Electricity and Water (Rancho)',
            'Electricity and Water (Antipolo HQ)',
            'Fuel and Oil',
            'Garbage Fee',
            'Internet and Communication Exp.',
            'Lasam Fam - Allowances',
            'Lasam Fam - Credit Card Bills',
            'Lasam Fam - Health Care',
            'Lasam Fam - School Fees',
            'Lasam Fam - Others',
            'Marketing Exp. - Customized (Allocations/GC/Sponsors)',
            'Marketing Exp. - IC Store (Allocations/GC/Sponsors)',
            "Marketing Exp. - Endorser's Allowance",
            'Marketing Exp. - Commission Fees',
            'Marketing Exp. - Event/Others',
            'Multimedia Expense',
            'Office Supplies Expense',
            'Other Materials/Supplies Expense',
            'Other Expense',
            'Repair & Maintenance - Gadgets',
            'Repair & Maintenance - Sewing Machine',
            'Repair & Maintenance - Other Machine',
            'Repair & Maintenance - Printer',
            'Repair & Maintenance - Office Equipment',
            'Repair & Maintenance - Service Vehicle',
            'Rent Expense - Antipolo Bldg.',
            'Rent Expense - Rancho',
            'Real Property Tax - Rancho',
            'Royalty Expense',
            'Service Fee (InstaPay/Pesonet/Others)',
            'Sprinkler - Materials and Labor Cost',
            'Tools & Equipment',
            'Training Expense',
            'Transportation & Travel Allowance',
        ],
    ];

    /** Every title, flat, for validating what came back from the form. */
    public static function accountTitles(): array
    {
        return array_merge(...array_values(self::ACCOUNT_TITLES));
    }

    /** Which group a title belongs to, for grouping a list of expenses. */
    public static function groupOf(?string $title): ?string
    {
        foreach (self::ACCOUNT_TITLES as $group => $titles) {
            if (in_array($title, $titles, true)) {
                return $group;
            }
        }

        return null;
    }

    /**
     * What kind of paper the reference number is written on.
     *
     * Kept apart from the number itself: "PO" and "0042" are two facts, and
     * jamming them into one box is how a column stops being sortable.
     */
    public const REFERENCE_TYPES = ['PCF', 'PO', 'RFP', 'Others'];

    /** Whether the receipt carries VAT. Blank is allowed - see the migration. */
    public const VAT_STATUSES = ['VAT', 'N-VAT'];

    /**
     * The tin the shop keeps notes and coins in, for the small things nobody
     * writes a bank transfer for.
     *
     * It is a method here and NOT on Payment, which is the other direction: a
     * client pays the shop, and they cannot pay out of the shop's own tin.
     *
     * The string must stay exactly as it appears in METHODS below. Two things
     * match on it - the balance guard that stops the tin paying out more than
     * it holds, and PettyCashTopup::balance() which counts what has left it -
     * and both fail SILENTLY if it drifts: the guard simply never fires and
     * the tin reads as though nothing had ever been spent from it.
     */
    public const METHOD_PETTY_CASH = 'Cash (Petty Cash Fund)';

    /**
     * How an expense was paid.
     *
     * The shop's own list, not Payment's. Money coming IN arrives by the
     * methods a client can use; money going OUT leaves by the shop's own tin,
     * phones and bank accounts, and the bookkeeper reconciles each against a
     * different statement. Naming the actual phone and the actual bank is the
     * whole point - "GCash" alone matches two accounts.
     *
     * Spelling is theirs, "Tranfer" included: these strings are matched
     * against their own workbook, and a silent correction here would be a
     * mismatch there.
     */
    public const METHODS = [
        'Cash (Petty Cash Fund)',
        'GCash (iPhone)',
        'GCash (Purchasing Phone)',
        'Bank Tranfer (AUB)',
        'Bank Tranfer (EWB)',
        'Bank Tranfer (UB)',
        'Others',
    ];

    protected $fillable = [
        'account_title', 'description', 'amount', 'spent_at', 'paid_at', 'method',
        'ordered_by', 'reference_type', 'reference', 'si_cr_no', 'supplier',
        'tin', 'business_address', 'vat_status',
        'receipt_path', 'receipt_name', 'note', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'spent_at' => 'date',
            'paid_at' => 'date',
        ];
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? ucfirst((string) $this->category);
    }

    public function hasReceipt(): bool
    {
        return filled($this->receipt_path);
    }

    /** Total spent between two dates (inclusive). */
    public static function totalBetween(string $from, string $to): float
    {
        return (float) self::whereBetween('spent_at', [$from, $to])->sum('amount');
    }
}
