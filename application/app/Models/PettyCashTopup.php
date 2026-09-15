<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Money put into the petty cash tin.
 *
 * The tin is not a separate set of books. What comes OUT of it is an ordinary
 * expense whose method is "Petty cash", and what comes IN is either a row
 * here or a client paying in cash - which physically goes in the same drawer.
 * So the balance is everything in, less everything spent - see balance() -
 * and nothing has to be kept in step by hand. Delete an expense and the money
 * is back in the tin, because the sum simply stops counting it.
 *
 * Nothing here is duplicated into a second ledger on purpose: a cash payment
 * recorded twice, once as a payment and once as a top-up, is two rows that
 * drift the first time one of them is corrected.
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

    /**
     * Cash a client handed over, counted once Finance has confirmed it.
     *
     * Every kind of it - downpayment, part payment, the final balance. Cash
     * is cash: a rule that put a downpayment in the drawer but not a part
     * payment would have the tin wrong the first time somebody paid in the
     * middle, and the tin is only worth counting if the number matches the
     * notes inside it.
     *
     * CONFIRMED only, and that is the whole care of this method. An
     * officer-recorded payment is a claim until Finance agrees - the same
     * rule hasDownpayment() applies before releasing the mockup - and here it
     * matters more than anywhere else, because the balance is what guards
     * recording an expense against the tin. Count a claim and the shop can
     * spend money it has not been shown.
     */
    public static function cashFromClients(): float
    {
        return (float) Payment::where('method', Payment::METHOD_CASH)
            ->whereNotNull('confirmed_at')
            ->sum('amount');
    }

    /** Cash that is in the drawer but not yet counted, because Finance has not confirmed it. */
    public static function cashAwaitingConfirmation(): float
    {
        return (float) Payment::where('method', Payment::METHOD_CASH)
            ->awaitingConfirmation()
            ->sum('amount');
    }

    /** Everything ever put in: topped up by hand, or paid in cash by a client. */
    public static function totalIn(): float
    {
        return round((float) self::sum('amount') + self::cashFromClients(), 2);
    }

    /** Only the rows on this table - what somebody walked to the bank for. */
    public static function totalToppedUp(): float
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
