<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    public const METHOD_OTHER_TRANSFER = 'Other transfer';

    public const METHODS = [
        'Cash',
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
