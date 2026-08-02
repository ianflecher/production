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

    /** Spending buckets, chosen to match how the shop actually spends. */
    public const CATEGORIES = [
        'raw_materials' => 'Raw materials',
        'salaries' => 'Salaries & wages',
        'rent' => 'Rent',
        'utilities' => 'Utilities (power, water, internet)',
        'equipment' => 'Equipment & maintenance',
        'supplies' => 'Shop supplies',
        'delivery' => 'Delivery & transport',
        'marketing' => 'Marketing',
        'taxes' => 'Taxes, permits & fees',
        'other' => 'Other',
    ];

    /** Payment methods, kept identical to Payment::METHODS so reports line up. */
    public const METHODS = Payment::METHODS;

    protected $fillable = [
        'category', 'description', 'amount', 'spent_at', 'method',
        'reference', 'receipt_path', 'receipt_name', 'note', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'spent_at' => 'date',
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
