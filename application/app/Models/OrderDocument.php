<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A client-facing document for an order.
 *
 *  - DR (delivery receipt): client does NOT need an official receipt, no VAT.
 *  - PQ (price quotation / invoice): client needs a receipt, +12% VAT.
 *
 * Most of the sheet is typed by the account officer; the fields that the system
 * already knows are pre-filled from the order (see defaultsFor()).
 */
class OrderDocument extends Model
{
    public const TYPE_DR = 'dr';

    public const TYPE_PQ = 'pq';

    public const TYPES = [
        self::TYPE_DR => 'Price Quotation',
        self::TYPE_PQ => 'Price Quotation with VAT',
    ];

    protected $fillable = ['production_order_id', 'type', 'number', 'items', 'fields', 'attachments', 'flatlay', 'created_by'];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'fields' => 'array',
            'attachments' => 'array',
            'flatlay' => 'array',
        ];
    }

    /** Files placed on the sheet (contract, payment proof, signed copy). */
    public function attachmentList(): array
    {
        return array_values($this->attachments ?? []);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? strtoupper($this->type);
    }

    public function isVat(): bool
    {
        return $this->type === self::TYPE_PQ;
    }

    /** A single typed field, falling back to the auto-filled default. */
    public function field(string $key, $default = null)
    {
        return $this->fields[$key] ?? $default;
    }

    /**
     * Which document an order gets by default: VAT-inclusive orders need an
     * official receipt (PQ), everything else gets a delivery receipt (DR).
     * The officer can still open the other one.
     */
    public static function defaultTypeFor(ProductionOrder $order): string
    {
        return $order->vat_inclusive ? self::TYPE_PQ : self::TYPE_DR;
    }

    /** Document number derived from the job order number (still editable). */
    public static function numberFor(ProductionOrder $order, string $type): string
    {
        // PQ = quotation, PQV = quotation with VAT.
        $prefix = $type === self::TYPE_PQ ? 'PQV' : 'PQ';
        // IC2026-00016 → INV2026-00016
        $tail = preg_replace('/^IC/i', '', (string) $order->order_number);

        return $prefix.($tail ?: $order->id);
    }

    /**
     * Everything the system already knows, used to pre-fill a fresh document.
     *
     * @return array{number: string, items: array<int, array>, fields: array<string, mixed>}
     */
    public static function defaultsFor(ProductionOrder $order, string $type): array
    {
        $order->loadMissing(['client', 'creator', 'items', 'jobOrder', 'tasks.assignee', 'payments']);

        $artist = $order->tasks->first(fn ($t) => $t->team === User::JOB_ARTIST && $t->assignee)?->assignee?->name;
        $paid = (float) $order->payments->sum('amount');
        $down = (float) ($order->payments->firstWhere('kind', 'downpayment')?->amount ?? 0);
        $pb = $order->pricingBreakdown();

        // One line per size on the order; the officer can edit or add rows.
        $items = $order->itemsInSizeOrder()->map(fn ($i) => [
            'description' => $i->description ?: ($order->productLabel() ?? ''),
            'size' => $i->size,
            'quantity' => $i->quantity,
            'unit_price' => $order->unit_price !== null ? (float) $order->unit_price : null,
        ])->values()->all();

        // Back pocket gets its own line so the garment price stays clean. It's an
        // "addon" — its quantity does not count toward the garment piece total.
        if ($order->backPocketCount() > 0) {
            $items[] = [
                'description' => 'Back pocket',
                'size' => '',
                'quantity' => $order->backPocketCount(),
                'unit_price' => (float) \App\Services\PricingService::backPocketFee(),
                'addon' => true,
            ];
        }

        // The Step 4 add-on (embroidery / sublimated / reflectorized / others)
        // is charged as one line, same as the back pocket.
        if ($order->addonAmount() > 0) {
            $items[] = [
                'description' => $order->addonLabel() ?: 'Add-on',
                'size' => '',
                'quantity' => 1,
                'unit_price' => $order->addonAmount(),
                'addon' => true,
            ];
        }

        // A rush job carries its own agreed fee, shown as its own line so the
        // client can see what the rush cost them.
        if ($order->rushAmount() > 0) {
            $items[] = [
                'description' => 'Rush fee',
                'size' => '',
                'quantity' => 1,
                'unit_price' => $order->rushAmount(),
                'addon' => true,
            ];
        }

        // The discount the order was given, as its own line.
        //
        // It was recorded on the order and applied there, but the sheet knew
        // nothing about it: the client was shown the full price, VAT was
        // charged on the undiscounted amount, and saving the sheet wrote that
        // larger figure back over the order's total. A line is what makes all
        // of those agree at once — every total on this document is built by
        // adding up the lines, so the discount is subtracted everywhere the
        // moment it is one of them.
        //
        // Capped at the gross by pricingBreakdown(), so it can never turn the
        // sheet into a negative amount.
        if ($pb['discount'] > 0) {
            $items[] = [
                'description' => 'Discount'.($order->discount_note ? ' — '.$order->discount_note : ''),
                'size' => '',
                'quantity' => 1,
                'unit_price' => -1 * (float) $pb['discount'],
                'addon' => true,
            ];
        }

        return [
            'number' => self::numberFor($order, $type),
            'items' => $items,
            'fields' => [
                // Bill to
                'bill_name' => $order->client?->fullName() ?: $order->customer_name,
                'company_name' => $order->client?->company,
                'bill_address' => $order->client?->delivery_address ?: $order->client?->office_address,
                'company_address' => $order->client?->office_address ?: $order->client?->delivery_address,
                'bill_tin' => $order->client?->tin,
                'contact_person' => $order->client?->fullName() ?: $order->customer_name,
                'contact_number' => $order->client?->contact_number,
                // Job details
                'date_ordered' => $order->created_at?->format('Y-m-d'),
                'delivery_date' => $order->due_date?->format('Y-m-d'),
                'account_officer' => $order->creator?->name,
                'artist' => $artist,
                'print_type' => $order->jobOrder?->printTypeLabel(),
                'materials' => implode(', ', $order->jobOrder?->rawMaterialsList() ?? []),
                'description' => $order->description,
                // Money
                'downpayment' => $down ?: null,
                'full_payment' => $paid ?: null,
                'total_balance' => $order->balance(),
                'total_vat' => $type === self::TYPE_PQ ? $pb['vat'] : null,
                // Signatures
                'prepared_by' => $order->creator?->name,
                'date_prepared' => now()->format('Y-m-d'),
            ],
        ];
    }

    /** Line totals + grand totals for the sheet. */
    public function totals(): array
    {
        $qty = 0;
        $amount = 0.0;

        foreach ($this->items ?? [] as $row) {
            $q = (int) ($row['quantity'] ?? 0);
            $u = (float) ($row['unit_price'] ?? 0);
            // Addon lines (e.g. back pocket) add to the money but not the garment count.
            if (empty($row['addon'])) {
                $qty += $q;
            }
            $amount += $q * $u;
        }

        // On what is owed after the discount line, matching the order's own
        // pricingBreakdown(). max() guards a sheet edited into the negative:
        // the shop does not refund VAT on a discount bigger than the job.
        $vat = $this->isVat()
            ? round(max(0, $amount) * ProductionOrder::VAT_RATE, 2)
            : 0.0;

        return [
            'quantity' => $qty,
            'amount' => round($amount, 2),
            'vat' => $vat,
            'net' => round($amount + $vat, 2),
        ];
    }
}
