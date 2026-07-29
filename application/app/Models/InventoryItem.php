<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryItem extends Model
{
    use SoftDeletes;

    /** Raw-material categories. */
    public const CATEGORIES = [
        'sewing' => 'Sewing raw materials',
        'printer' => 'Printer raw materials',
        'fabric' => 'Fabric',
        'production' => 'Production raw materials',
    ];

    protected $fillable = [
        'name', 'category', 'code', 'photo', 'size', 'color',
        'unit', 'quantity', 'beginning_stock',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'beginning_stock' => 'decimal:2',
        ];
    }

    /** Human category label. */
    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? ($this->category ?: 'Uncategorised');
    }

    /** Beginning stock without trailing .00 when whole. */
    public function beginningForHumans(): string
    {
        $q = (float) $this->beginning_stock;

        return $q == (int) $q ? number_format($q) : number_format($q, 2);
    }

    /** Stock in/out history, newest first. */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class)->latest('id');
    }

    /**
     * Change the stock and log who did it. Positive $delta adds, negative takes
     * out. Returns the movement so callers can reference it.
     */
    public function recordMovement(
        float $delta,
        string $reason,
        ?string $note = null,
        ?int $orderId = null,
        ?string $operatorName = null,
    ): ?StockMovement {
        if ($delta == 0.0) {
            return null;
        }

        $this->update(['quantity' => max(0, (float) $this->quantity + $delta)]);

        return $this->movements()->create([
            'direction' => $delta > 0 ? StockMovement::IN : StockMovement::OUT,
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
