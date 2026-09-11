<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One repayment against a loan. */
class HrLoanPayment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['hr_loan_id', 'amount', 'paid_on', 'note', 'recorded_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_on' => 'date'];
    }

    public function loan()
    {
        return $this->belongsTo(HrLoan::class, 'hr_loan_id');
    }
}
