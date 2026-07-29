<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change to a raw material's stock — who added it, or who took it out.
 * Written by InventoryItem::recordMovement(), never edited afterwards.
 */
class StockMovement extends Model
{
    public const IN = 'in';

    public const OUT = 'out';

    protected $fillable = [
        'inventory_item_id', 'direction', 'quantity', 'balance_after',
        'reason', 'note', 'production_order_id', 'user_id', 'operator_name',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'balance_after' => 'decimal:2',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    public function isIn(): bool
    {
        return $this->direction === self::IN;
    }

    /** Who actually moved the stock — the typed name wins over the account. */
    public function operator(): string
    {
        return $this->operator_name ?: ($this->user?->name ?? 'system');
    }

    /** True when the typed name differs from the account it was logged under. */
    public function loggedUnderDifferentAccount(): bool
    {
        return filled($this->operator_name)
            && $this->user
            && trim(mb_strtolower($this->operator_name)) !== trim(mb_strtolower($this->user->name));
    }

    /** e.g. "+12.00" / "−3.00" */
    public function signedQty(): string
    {
        $n = rtrim(rtrim(number_format((float) $this->quantity, 2), '0'), '.');

        return ($this->isIn() ? '+' : '−').$n;
    }
}
