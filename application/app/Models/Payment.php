<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    public const METHODS = ['Cash', 'GCash', 'Bank Transfer'];

    protected $fillable = [
        'production_order_id', 'amount', 'method', 'reference',
        'proof_path', 'proof_name', 'kind', 'note', 'paid_at', 'recorded_by',
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
        ];
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
