<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialRequest extends Model
{
    protected $fillable = [
        'production_order_id', 'material', 'kind', 'size', 'status',
        'inventory_item_id', 'quantity', 'requested_quantity', 'issued_quantity', 'note', 'decided_by', 'decided_by_name', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
            'quantity' => 'decimal:2',
            'requested_quantity' => 'decimal:2',
            'issued_quantity' => 'decimal:2',
        ];
    }

    /**
     * The material and the size it is being asked for, as one phrase.
     *
     * An order with no size breakdown asks for the material plainly, which is
     * what the desk saw before sizes were split out.
     */
    public function label(): string
    {
        return filled($this->size)
            ? $this->material.' · size '.$this->size
            : $this->material;
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

    /**
     * Who decided this, as a person.
     *
     * The supply desk is a shared login, so the name typed on the form is the
     * answer; the account it came through is a fallback for older rows that
     * were recorded before the box was kept.
     */
    public function decidedByLabel(): string
    {
        return filled($this->decided_by_name)
            ? $this->decided_by_name
            : ($this->decider?->name ?? '—');
    }

    /**
     * The stock this request comes out of.
     *
     * The desk used to pick it from a dropdown of every material in the shop,
     * which is a question with one right answer — the material is written on
     * the request — and a thousand wrong ones. So the system answers it.
     *
     * Matched on MaterialName::key() rather than the literal name, because a
     * stock sheet typed by hand comes back as "Cotton White XL", "cotton white
     * xl" and "COTTON-WHITE-XL" on three different days; and through
     * MaterialAlias, because a job order asking for QA700 means the fabric the
     * stock sheet files under QUIANA.
     *
     * Kept to its own shelf: the supervisor's fabric is not the desk's
     * ready-made stock, and a request must not deduct from the other one.
     */
    public function stockItem(): ?InventoryItem
    {
        $shelf = InventoryItem::shelfIndex($this->kind);

        foreach (MaterialAlias::keysFor($this->material) as $key) {
            if (isset($shelf[$key])) {
                return $shelf[$key];
            }
        }

        return null;
    }
}
