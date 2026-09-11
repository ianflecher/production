<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One pay period, as HR worked it out.
 *
 * Gross and the lines are typed; the net is added up from them so the sheet
 * cannot disagree with itself. What the shop owes is not calculated here — see
 * the migration for why.
 */
class HrPayslip extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'hr_employee_id', 'period_start', 'period_end', 'gross',
        'earnings', 'deductions', 'net', 'note', 'released_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'gross' => 'decimal:2',
            'net' => 'decimal:2',
            'earnings' => 'array',
            'deductions' => 'array',
            'released_at' => 'datetime',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'hr_employee_id');
    }

    public function isReleased(): bool
    {
        return $this->released_at !== null;
    }

    /** Only lines with both a label and an amount count. */
    public static function tidyLines(?array $lines): array
    {
        return array_values(array_filter(
            array_map(fn ($l) => [
                'label' => trim((string) ($l['label'] ?? '')),
                'amount' => round((float) ($l['amount'] ?? 0), 2),
            ], $lines ?? []),
            fn ($l) => $l['label'] !== '' && $l['amount'] != 0.0
        ));
    }

    public static function sumLines(?array $lines): float
    {
        return round(array_sum(array_column($lines ?? [], 'amount')), 2);
    }

    /** Gross, plus anything extra, less everything taken off. */
    public function recomputeNet(): void
    {
        $this->net = round(
            (float) $this->gross
            + self::sumLines($this->earnings)
            - self::sumLines($this->deductions),
            2
        );
    }
}
