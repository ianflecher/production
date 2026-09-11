<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Money put into the petty cash tin.
 *
 * The tin is not a separate set of books: what comes OUT of it is an ordinary
 * expense whose method is "Petty cash". So the balance is everything put in,
 * less everything spent from it - see balance() - and nothing has to be kept
 * in step by hand. Delete an expense and the money is back in the tin,
 * because the sum simply stops counting it.
 */
class PettyCashTopup extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['amount', 'note', 'occurred_at', 'recorded_by'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'occurred_at' => 'date',
        ];
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** Everything ever put in. */
    public static function totalIn(): float
    {
        return (float) self::sum('amount');
    }

    /** Everything ever spent out of the tin. */
    public static function totalOut(): float
    {
        return (float) Expense::where('method', Expense::METHOD_PETTY_CASH)->sum('amount');
    }

    /** What is in the tin right now. */
    public static function balance(): float
    {
        return round(self::totalIn() - self::totalOut(), 2);
    }
}
