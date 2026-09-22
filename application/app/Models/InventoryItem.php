<?php

namespace App\Models;

use App\Services\MaterialName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryItem extends Model
{
    /**
     * One shelf, keyed by material name, read once per request.
     *
     * The material requests page asks "which stock is this line?" for every row
     * on it. Asking the database each time is a query a row; loading the whole
     * of both shelves is 1,670 rows to answer a question about six. So each
     * shelf is read at most once and held.
     *
     * Keyed on MaterialName::key(), because a stock sheet typed by hand spells
     * one fabric three ways and none of them is wrong.
     *
     * Held on the container rather than in a static, so it lasts exactly one
     * request and no longer. A static outlives the request, which in a test run
     * means one test's shelf answering another's question.
     */
    private const SHELVES = 'inventory.shelves';

    /** @return array<string, static> key => the item */
    public static function shelfIndex(?string $kind): array
    {
        $shelves = app()->bound(self::SHELVES) ? app(self::SHELVES) : [];
        $cacheKey = $kind ?? '*';

        if (isset($shelves[$cacheKey])) {
            return $shelves[$cacheKey];
        }

        $index = [];

        static::query()
            ->when($kind !== null, fn ($q) => $q->where('kind', $kind))
            ->orderBy('id')
            ->get()
            ->each(function ($item) use (&$index) {
                $key = MaterialName::key($item->name);

                // First row wins: two rows spelt the same way are one material
                // typed twice, and the older one is the one in use.
                if ($key !== '' && ! isset($index[$key])) {
                    $index[$key] = $item;
                }
            });

        $shelves[$cacheKey] = $index;
        app()->instance(self::SHELVES, $shelves);

        return $index;
    }

    /** Read the shelves again — after stock moves, or in a test. */
    public static function forgetShelves(): void
    {
        app()->forgetInstance(self::SHELVES);
    }

    protected static function booted(): void
    {
        // A row added, renamed or taken away changes the shelf, so what was
        // read before it is no longer what is there.
        static::saved(fn () => self::forgetShelves());
        static::deleted(fn () => self::forgetShelves());
    }

    use SoftDeletes;

    /** Raw-material categories. */
    /**
     * The stock groups, taken from the shop's own RAW MATERIALS STOCKS sheet
     * so the app reads the same way the spreadsheet does. The sheet's name is
     * used as the stored value too, which keeps the import and the database
     * readable without a lookup table.
     */
    /**
     * Which shelf this belongs to.
     *
     * The raw materials desk holds the ready-made stock — shirts, caps,
     * boxes, tapes — counted in pieces. The raw materials supervisor holds
     * the fabric: bolts, by the kilo. Same act either way (count it, issue it
     * against a job, log the movement), so it is one table told apart by this
     * rather than two tables doing the same work twice.
     */
    public const KIND_READY_MADE = 'ready_made';

    public const KIND_FABRIC = 'fabric';

    public const KINDS = [
        self::KIND_READY_MADE => 'Ready-made stock',
        self::KIND_FABRIC => 'Fabric',
    ];

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
        'name', 'category', 'kind', 'code', 'photo', 'size', 'color',
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
