<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryItem extends Model
{
    use SoftDeletes;

    /** Raw-material categories. */
    /**
     * The stock groups, taken from the shop's own RAW MATERIALS STOCKS sheet
     * so the app reads the same way the spreadsheet does. The sheet's name is
     * used as the stored value too, which keeps the import and the database
     * readable without a lookup table.
     */
    public const CATEGORIES = [
        'BOND PAPER HARD COPY' => 'Bond paper (hard copy)',
        'BOX' => 'Box',
        'BREAST PAD/ BRA PAD' => 'Breast pad / bra pad',
        'CANVASS BAG' => 'Canvass bag',
        'COTTON SHIRT' => 'Cotton shirt',
        'ECO BAG' => 'Eco bag',
        'FLAG POLE' => 'Flag pole',
        'FOLDING CHAIR' => 'Folding chair',
        'HEADMASK' => 'Headmask',
        'HOODIE' => 'Hoodie',
        'HOODIE W/ ZIPPER' => 'Hoodie with zipper',
        'HOT MELT' => 'Hot melt',
        'JACKET' => 'Jacket',
        'LONGSLEEVE' => 'Longsleeve',
        'MOUSE PAD' => 'Mouse pad',
        'MUGS' => 'Mugs',
        'PANTS' => 'Pants',
        'PAPER BAG' => 'Paper bag',
        'PLASTIC' => 'Plastic',
        'PLASTIC BAG' => 'Plastic bag',
        'POLO SHIRT' => 'Polo shirt',
        'SANDO' => 'Sando',
        'STAND' => 'Stand',
        'SWEATER' => 'Sweater',
        'TAPE' => 'Tape',
        'TAPES' => 'Tapes',
        'THERMAL PAPER' => 'Thermal paper',
        'TISSUE PAPER/ PAPER FOR BOX' => 'Tissue paper / paper for box',
        'TOWEL' => 'Towel',
        'UMBRELLA' => 'Umbrella',
        'WIND BREAKER' => 'Wind breaker',
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

    /*
     * The four running figures the shop's stock sheet shows. They are worked
     * out from the movement history rather than stored, so they can never drift
     * from what actually happened:
     *
     *   BEG BAL   beginning_stock  (the opening count)
     *   RECEIVED  everything in
     *   TOTAL     beginning + received
     *   LESS      everything out
     *   REMAINING quantity         (= total − less)
     */

    /**
     * Everything received since the opening count. The opening itself is
     * logged as an 'added' movement so the history shows who entered it, but
     * it belongs to beginning_stock — counting it here would double it.
     */
    public function receivedTotal(): float
    {
        // Use the summed column when the list query preloaded it.
        if (array_key_exists('received_sum', $this->attributes)) {
            return (float) $this->attributes['received_sum'];
        }

        return (float) $this->movements()
            ->where('direction', StockMovement::IN)
            ->where('reason', '!=', 'added')
            ->sum('quantity');
    }

    /** Everything issued out since the opening count. */
    public function lessTotal(): float
    {
        if (array_key_exists('less_sum', $this->attributes)) {
            return (float) $this->attributes['less_sum'];
        }

        return (float) $this->movements()->where('direction', StockMovement::OUT)->sum('quantity');
    }

    /** Opening count plus everything received. */
    public function runningTotal(): float
    {
        return (float) $this->beginning_stock + $this->receivedTotal();
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
