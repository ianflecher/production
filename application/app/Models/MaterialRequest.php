<?php

namespace App\Models;

use App\Services\MaterialName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

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
     * The stock rows this request could come out of.
     *
     * The material is written on the request, so the desk should not be picking
     * it out of a list of every material in the shop. But a job order names a
     * FABRIC — QA700, which the stock sheet files under QUIANA — and the shelf
     * holds ten of it: two weights, eight colours. The request says which
     * fabric, never which bolt.
     *
     * So: everything on this desk's shelf that is that material, or a kind of
     * it. One row means there is nothing to ask. Several means the desk picks
     * from those several rather than from sixteen hundred.
     *
     * Aliases both ways round, because the sheet and the job order have never
     * agreed on what to call it.
     *
     * @return Collection<int, InventoryItem>
     */
    public function stockCandidates(): Collection
    {
        $wanted = MaterialAlias::keysFor($this->material);

        if ($wanted === []) {
            return collect();
        }

        return collect(InventoryItem::shelfIndex($this->kind))
            ->filter(fn ($item, $key) => collect($wanted)
                ->contains(fn ($name) => MaterialName::sameFamily($key, $name)))
            ->sortBy(fn ($item) => $item->name)
            ->values();
    }

    /**
     * The one row it obviously comes out of, when there is only one.
     *
     * Null when the shelf holds none of it, and null when it holds several:
     * both are questions, and neither is the system's to answer quietly.
     */
    public function stockItem(): ?InventoryItem
    {
        $candidates = $this->stockCandidates();

        return $candidates->count() === 1 ? $candidates->first() : null;
    }
}
