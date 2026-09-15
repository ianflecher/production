<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    public const METHOD_OTHER_TRANSFER = 'Other transfer';

    /**
     * Named, because the petty cash tin matches on it.
     *
     * Cash a client hands over goes into the tin, so PettyCashTopup counts
     * every confirmed payment with this method as money in. Spelled as a
     * literal in both places, renaming one would leave the tin quietly
     * counting nothing and nobody would see it until the drawer disagreed
     * with the screen.
     */
    public const METHOD_CASH = 'Cash';

    public const METHODS = [
        self::METHOD_CASH,
        'GCash',
        'Bank transfer – UnionBank',
        'Tayocash – EastWest',
        self::METHOD_OTHER_TRANSFER,
    ];

    protected $fillable = [
        'production_order_id', 'amount', 'method', 'reference',
        'proof_path', 'proof_name', 'kind', 'note', 'paid_at', 'confirmed_at', 'confirmed_by', 'confirmed_name', 'recorded_by',
    ];

    public function hasProof(): bool
    {
        return ! empty($this->proof_path);
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    /** Finance has seen the money land. Until then it is a claim. */
    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    /**
     * Money recorded by an officer that finance has not agreed to yet.
     *
     * Written once and used by all three: the badge in the sidebar, the card
     * on the finance desk's dashboard, and the ledger page itself. A badge
     * that counts a different thing from the page it opens is worse than no
     * badge, because it sends somebody looking for work that is not there.
     *
     * This is the same gap the designing board calls "Waiting for finance" -
     * the job does not start on it, and the person holding it is not the
     * client.
     */
    public function scopeAwaitingConfirmation($query)
    {
        return $query
            ->whereNull('confirmed_at')
            // A cancelled job's money is not work waiting on this desk. The
            // finance dashboard has always left those out; a badge counting
            // them would send somebody to a page that does not list them.
            ->whereHas('order', fn ($q) => $q->where('status', '!=', 'cancelled'));
    }

    /**
     * Who confirmed it, in the shop's own terms.
     *
     * The signed-in Finance account is the source of truth. The saved name is
     * retained only as a fallback for confirmations made before account-based
     * attribution was introduced.
     */
    public function confirmedByName(): ?string
    {
        return $this->confirmer?->name ?: $this->confirmed_name;
    }

    public function confirmer()
    {
        return $this->belongsTo(\App\Models\User::class, 'confirmed_by');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
