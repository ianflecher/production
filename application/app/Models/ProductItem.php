<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A finished product held in stock. Auto-created when an order finishes and
 * released when a client receives it. Sister of InventoryItem (raw materials).
 */
class ProductItem extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'unit', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2'];
    }

    /** Stock in/out history, newest first. */
    public function movements(): HasMany
    {
        return $this->hasMany(ProductMovement::class)->latest('id');
    }

    /**
     * Change the stock and log who did it. Positive $delta adds, negative
     * takes out. Returns the movement so callers can reference it.
     */
    public function recordMovement(
        float $delta,
        string $reason,
        ?string $note = null,
        ?int $orderId = null,
        ?string $operatorName = null,
    ): ?ProductMovement {
        if ($delta == 0.0) {
            return null;
        }

        $this->update(['quantity' => max(0, (float) $this->quantity + $delta)]);

        return $this->movements()->create([
            'direction' => $delta > 0 ? ProductMovement::IN : ProductMovement::OUT,
            'quantity' => abs($delta),
            'balance_after' => (float) $this->fresh()->quantity,
            'reason' => $reason,
            'note' => $note,
            'production_order_id' => $orderId,
            'user_id' => auth()->id(),
            'operator_name' => $operatorName ? trim($operatorName) : null,
        ]);
    }

    /** Stock shown without trailing .00 when whole. */
    public function qtyForHumans(): string
    {
        $q = (float) $this->quantity;

        return $q == (int) $q ? number_format($q) : number_format($q, 2);
    }
}
