<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Money lent to somebody, and what is left of it.
 *
 * The balance is the principal less what has actually been paid back, never a
 * number kept by hand — the same reasoning as the petty cash tin. Delete a
 * payment and the balance goes back up on its own.
 */
class HrLoan extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'hr_employee_id', 'principal', 'reason', 'borrowed_on',
        'per_payslip', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'principal' => 'decimal:2',
            'per_payslip' => 'decimal:2',
            'borrowed_on' => 'date',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'hr_employee_id');
    }

    public function payments()
    {
        return $this->hasMany(HrLoanPayment::class)->orderByDesc('paid_on');
    }

    public function paid(): float
    {
        return round((float) $this->payments()->sum('amount'), 2);
    }

    public function balance(): float
    {
        return round(max(0, (float) $this->principal - $this->paid()), 2);
    }

    public function isSettled(): bool
    {
        return $this->balance() <= 0.0;
    }
}
