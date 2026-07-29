<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialRequest extends Model
{
    protected $fillable = [
        'production_order_id', 'material', 'status',
        'inventory_item_id', 'quantity', 'note', 'decided_by', 'decided_at',
    ];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime', 'quantity' => 'decimal:2'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
