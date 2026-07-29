<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A finished product waiting to be received into stock. Queued when an order
 * finishes; the products desk confirms the quantity actually received.
 */
class ProductReceipt extends Model
{
    protected $fillable = [
        'production_order_id', 'name', 'unit', 'expected_quantity',
        'received_quantity', 'status', 'product_item_id', 'received_by', 'received_at',
    ];

    protected function casts(): array
    {
        return [
            'expected_quantity' => 'decimal:2',
            'received_quantity' => 'decimal:2',
            'received_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ProductItem::class, 'product_item_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /** Expected quantity without trailing .00 when whole. */
    public function expectedForHumans(): string
    {
        $q = (float) $this->expected_quantity;

        return $q == (int) $q ? number_format($q) : number_format($q, 2);
    }
}
