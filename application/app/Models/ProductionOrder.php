<?php

namespace App\Models;

use App\Services\StaffAssigner;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductionOrder extends Model
{
    /** The artist layout is stage 1; the final mockup (released after the job
     *  order is sent) is stage 2. Used to pause the pipeline between them. */
    public const STAGE_LAYOUT = 1;

    public const STAGE_MOCKUP = 2;

    /** Where the sample run ends and the batch run begins. */
    public const STAGE_MASS_PRODUCTION = 10;

    /**
     * The two tech pack steps, spelled once.
     *
     * The sample sheet is drawn in stage 2 off the approved mockup. The batch
     * sheet is a stage-10 step, so it does not exist until the client has held
     * the sample and approved it: what the client asked to change is written on
     * the batch sheet, and the sample sheet stays as the record of what they
     * approved. isTechPackStep() matches both by their shared prefix.
     */
    public const STEP_TECH_PACK = 'Tech pack';

    public const STEP_TECH_PACK_MASSPROD = 'Tech pack (mass production)';

    public const STATUS_LABELS = [
        'active' => 'ACTIVE',
        'on_hold' => 'ON HOLD',
        'complete' => 'COMPLETE',
        'cancelled' => 'CANCELLED',
    ];

    /*
     * The shop has two presses. Cap press and heat press were on this list and
     * on the floor plan, and neither exists - so a job could be sent to a
     * machine that is not there, and the board showed benches nobody stands at.
     */
    public const DECORATION_METHODS = [
        'embroidery' => 'Embroidery',
        'small_press' => 'Small press',
        'roller_press' => 'Roller press',
    ];

    public const CUTTING_TYPES = [
        'manual' => 'Manual cutting',
        'laser' => 'Laser cutting',
    ];

    /** Sizes offered at inquiry (matches the Imprint size charts). Anything not
     *  listed here is captured as a typed "Others" size on the order form. */
    public const SIZES = ['CS', 'FS', '2XS', 'XS', 'S', 'M', 'L', 'XL', '2XL', '3XL', '4XL', '5XL', '6XL'];

    /** The order statuses, in the order the office works through them. */
    public const STATUSES = ['active', 'on_hold', 'complete', 'cancelled'];

    /** Maximum pieces that may be due on any single date. */
    public const DAILY_CAPACITY = 500;

    /**
     * How long a finished job stays on the lists.
     *
     * Sixty days after it is completed it is delivered, paid and settled. It
     * stops being work and becomes history — still here, still searchable by
     * its number, just not in the way of the jobs somebody is doing today.
     */
    public const ARCHIVE_AFTER_DAYS = 60;

    /** VAT added to the total when the order is marked VAT inclusive. */
    public const VAT_RATE = 0.12;

    /** The artwork/layout charge is returned as a credit on qualifying orders. */
    public const LAYOUT_FEE = 500.0;

    /** At 24 pieces, the client receives the layout fee back in full. */
    public const LAYOUT_FEE_REFUND_QTY = 24;

    /**
     * A due date this close is a rush job.
     *
     * Ten days is roughly what the line needs to go from layout to a garment
     * in a box without anybody skipping a step or working a Sunday. Shorter
     * than that is sometimes the right call — but it should be a decision
     * somebody makes on purpose, not something noticed later on the calendar.
     */
    public const RUSH_NOTICE_DAYS = 10;

    /**
     * How long the shop has to put a sample in front of the client.
     *
     * Three days from the confirmed payment. The order's own due date is the
     * promise to the client about the finished batch; this is the shop's
     * promise to itself about the sample, and a sample that quietly sits for a
     * week used to surface only when the batch behind it ran short.
     */
    public const SAMPLE_LEAD_DAYS = 3;

    /** A riding jersey is panelled and takes a longer press, so it gets a
     *  fourth day rather than being late by design on every order. */
    public const SAMPLE_LEAD_DAYS_JERSEY = 4;

    /** Both jersey lines on the price list take the longer sample. */
    public const JERSEY_PRODUCT_TYPES = ['riding_jersey', 'regular_riding_jersey'];

    protected $fillable = [
        'order_number', 'brief_token', 'brief_expires_at', 'client_id', 'customer_name', 'product_type', 'price_list', 'description',
        'inquiry_id', 'inquiry_design_id',
        'decoration_methods', 'cutting_type', 'needs_sticker',
        'massprod_priority', 'skip_sample', 'back_pocket', 'back_pocket_qty',
        'rush', 'rush_fee', 'shipping_cost', 'charge_layout_fee',
        'unit_price', 'custom_size_price', 'total_price', 'vat_inclusive', 'withholding_rate', 'discount_amount', 'discount_note', 'downpayment_waived', 'downpayment_waiver_note',
        'quantity', 'due_date', 'sample_due_date', 'layout_approved_at', 'status', 'completed_at', 'created_by',
        'mockup_offset_x', 'mockup_offset_y',
        'replaces_order_id', 'replacement_reason',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'sample_due_date' => 'date',
            'layout_approved_at' => 'datetime',
            'completed_at' => 'datetime',
            'decoration_methods' => 'array',
            'back_pocket' => 'boolean',
            'back_pocket_qty' => 'integer',
            'massprod_priority' => 'boolean',
            'skip_sample' => 'boolean',
            'rush' => 'boolean',
            'rush_fee' => 'decimal:2',
            'charge_layout_fee' => 'boolean',
            'shipping_cost' => 'decimal:2',
            'needs_sticker' => 'boolean',
            'unit_price' => 'decimal:2',
            'custom_size_price' => 'decimal:2',
            'total_price' => 'decimal:2',
            'vat_inclusive' => 'boolean',
            'withholding_rate' => 'integer',
            'discount_amount' => 'decimal:2',
            'downpayment_waived' => 'boolean',
            'brief_expires_at' => 'datetime',
            'mockup_offset_x' => 'integer',
            'mockup_offset_y' => 'integer',
        ];
    }

    /** Keep the customer name in Title Case so it matches the client record. */
    protected function customerName(): Attribute
    {
        return Attribute::make(set: fn ($v) => filled($v) ? Str::title(trim((string) $v)) : $v);
    }

    /** How long a shared client questionnaire link stays valid. */
    public const BRIEF_LINK_DAYS = 30;

    protected static function booted(): void
    {
        // Every new order gets a random, unguessable token for its public
        // design-questionnaire link, plus an expiry date.
        static::creating(function (self $order) {
            $order->brief_token ??= (string) Str::random(32);
            $order->brief_expires_at ??= now()->addDays(self::BRIEF_LINK_DAYS);
        });
    }

    /** The public questionnaire link has passed its expiry date. */
    public function briefExpired(): bool
    {
        return $this->brief_expires_at !== null && $this->brief_expires_at->isPast();
    }

    /** Issue a fresh token + expiry — instantly kills the old link. */
    public function regenerateBriefLink(): void
    {
        $this->update([
            'brief_token' => (string) Str::random(32),
            'brief_expires_at' => now()->addDays(self::BRIEF_LINK_DAYS),
        ]);
    }

    /**
     * The money math in one place so every screen shows the same figures:
     * subtotal → less discount → plus 12% VAT (when ticked) → less any
     * 1% or 2% withholding tax → total.
     *
     * @return array{subtotal: ?float, discount: float, vatable: ?float, vat: float, withholding_rate: int, withholding: float, total: ?float}
     */
    /** How many pieces carry a back pocket (0..quantity). */
    public function backPocketCount(): int
    {
        if (! $this->back_pocket) {
            return 0;
        }

        $q = $this->back_pocket_qty ?? $this->quantity;

        return max(0, min((int) $q, (int) $this->quantity));
    }

    /** The back-pocket charge: fee × number of pieces with a pocket. */
    public function backPocketAmount(): float
    {
        return $this->backPocketCount() * (float) \App\Services\PricingService::backPocketFee();
    }

    /**
     * What the add-on (embroidery / sublimated / reflectorized / others) is
     * charged at. Set on the job order at Step 4, so it lands after intake —
     * recomputeTotal() folds it into the order total when it changes.
     */
    public function addonAmount(): float
    {
        return (float) ($this->jobOrder?->addon_price ?? 0);
    }

    /** The add-on's label for money lines, or null when there isn't one. */
    public function addonLabel(): ?string
    {
        return $this->jobOrder?->addonLabel();
    }

    /** The rush charge, or zero when the order isn't a rush job. */
    public function rushAmount(): float
    {
        return $this->rush ? (float) $this->rush_fee : 0.0;
    }

    /** Delivery charged to this order, agreed by the account officer. */
    public function shippingAmount(): float
    {
        return max(0, (float) $this->shipping_cost);
    }

    /**
     * The pieces the price list does not cover: CS, and a typed size such as
     * "Kids 8" that is not on the chart. They are priced by hand.
     */
    public function customSizeQty(): int
    {
        return (int) $this->items
            ->filter(fn ($i) => self::isCustomSize($i->size))
            ->sum('quantity');
    }

    /** Is this size off the chart, so no tier price applies to it? */
    public static function isCustomSize(?string $size): bool
    {
        return $size === 'CS' || ! in_array($size, self::SIZES, true);
    }

    /**
     * The layout fee is waived when the discount already makes the actual work
     * free. A free order must not quietly become a ₱500 layout charge.
     */
    public function layoutFeeAmount(): float
    {
        // Asked for, not assumed. It used to go on every quotation, and an
        // officer who did not want it had to discount it back off — which
        // read on the sheet as the shop giving money away rather than as a
        // fee that was never charged.
        if (! $this->charge_layout_fee) {
            return 0.0;
        }

        $work = $this->workAmountBeforeLayout();

        if ($work === null || (float) $this->discount_amount >= $work) {
            return 0.0;
        }

        return self::LAYOUT_FEE;
    }

    /** The work total before a layout fee, discount, VAT, or withholding. */
    public function workAmountBeforeLayout(): ?float
    {
        $custom = $this->custom_size_price !== null ? $this->customSizeQty() : 0;
        $charted = max(0, (int) $this->quantity - $custom);

        if ($this->unit_price === null) {
            return null;
        }

        $garment = ((float) $this->unit_price * $charted) + ((float) $this->custom_size_price * $custom);

        return $garment + $this->backPocketAmount() + $this->addonAmount()
            + $this->rushAmount() + $this->shippingAmount();
    }

    /** The layout fee is credited back once the client orders 24 pieces. */
    public function layoutFeeRefund(): float
    {
        return $this->quantity >= self::LAYOUT_FEE_REFUND_QTY ? $this->layoutFeeAmount() : 0.0;
    }

    public function pricingBreakdown(): array
    {
        // The charted sizes are on the automatic tier price; the off-chart
        // ones are on their own price, when one has been set. Without a custom
        // price they fall back to the tier — the way it worked before.
        $custom = $this->custom_size_price !== null ? $this->customSizeQty() : 0;
        $charted = max(0, (int) $this->quantity - $custom);

        $garment = $this->unit_price !== null
            ? ((float) $this->unit_price * $charted) + ((float) $this->custom_size_price * $custom)
            : null;

        if ($garment === null) {
            return ['subtotal' => null, 'charted_qty' => 0, 'custom_size_qty' => 0, 'custom_size_amount' => 0.0, 'back_pocket' => 0.0, 'back_pocket_qty' => 0, 'addon' => 0.0, 'addon_label' => null, 'rush' => 0.0, 'shipping' => 0.0, 'layout_fee' => 0.0, 'layout_fee_refund' => 0.0, 'discount' => 0.0, 'vatable' => null, 'vat' => 0.0, 'withholding_rate' => 0, 'withholding' => 0.0, 'total' => null];
        }

        $backPocket = $this->backPocketAmount();
        $addon = $this->addonAmount();
        $rush = $this->rushAmount();
        $shipping = $this->shippingAmount();
        $workBeforeLayout = $garment + $backPocket + $addon + $rush + $shipping;
        // layoutFeeAmount() carries the tick and the waivers together, so the
        // breakdown cannot disagree with the model about whether it applies.
        $layoutFee = $this->layoutFeeAmount();
        $layoutFeeRefund = $this->layoutFeeRefund();
        $gross = $garment + $backPocket + $addon + $rush + $shipping + $layoutFee - $layoutFeeRefund; // before discount
        $discount = min((float) $this->discount_amount, $gross);
        $vatable = round($gross - $discount, 2);
        $vat = $this->vat_inclusive ? round($vatable * self::VAT_RATE, 2) : 0.0;
        $withholdingRate = $this->vat_inclusive && in_array((int) $this->withholding_rate, [1, 2], true)
            ? (int) $this->withholding_rate
            : 0;
        $withholding = round($vatable * ($withholdingRate / 100), 2);

        return [
            'subtotal' => round($garment, 2),            // garment lines only
            'charted_qty' => $charted,
            'custom_size_qty' => $custom,
            'custom_size_amount' => round((float) $this->custom_size_price * $custom, 2),
            'back_pocket' => round($backPocket, 2),
            'back_pocket_qty' => $this->backPocketCount(),
            'addon' => round($addon, 2),
            'addon_label' => $this->addonLabel(),
            'rush' => round($rush, 2),
            'shipping' => round($shipping, 2),
            'layout_fee' => $layoutFee,
            'layout_fee_refund' => $layoutFeeRefund,
            'discount' => round($discount, 2),
            'vatable' => $vatable,
            'vat' => $vat,
            'withholding_rate' => $withholdingRate,
            'withholding' => $withholding,
            'total' => round($vatable + $vat - $withholding, 2),
        ];
    }

    /**
     * Recompute total_price from the current breakdown. Called when something
     * priced changes AFTER intake — the Step 4 add-on, and at intake once the
     * size breakdown exists (the off-chart pieces carry their own price, so
     * the total cannot be known until the items are saved).
     */
    public function recomputeTotal(): void
    {
        if ($this->unit_price === null) {
            return;     // still a quotation — nothing to recompute
        }

        $this->update(['total_price' => $this->pricingBreakdown()['total']]);
    }

    /**
     * Compute the total for a given set of figures (used when saving an order,
     * before the model is persisted).
     */
    public static function computeTotal(?float $unitPrice, int $qty, float $discount = 0, bool $vat = false, float $backPocketAmount = 0, float $extras = 0, int $withholdingRate = 0, float $shippingCost = 0, bool $chargeLayoutFee = false): ?float
    {
        if ($unitPrice === null) {
            return null;
        }

        // $extras covers one-off charges on the job rather than per piece —
        // the rush fee, and the Step 4 add-on.
        $workBeforeLayout = ($unitPrice * $qty) + $backPocketAmount + $extras + max(0, $shippingCost);
        // Only when it was ticked, and still never on a job the discount has
        // already taken to nothing.
        $layoutFee = (! $chargeLayoutFee || $discount >= $workBeforeLayout) ? 0.0 : self::LAYOUT_FEE;
        $layoutRefund = $qty >= self::LAYOUT_FEE_REFUND_QTY ? $layoutFee : 0.0;
        $vatable = max(0, $workBeforeLayout + $layoutFee - $layoutRefund - $discount);

        $withholdingRate = $vat && in_array($withholdingRate, [1, 2], true) ? $withholdingRate : 0;
        $withholding = $vatable * ($withholdingRate / 100);

        return round($vat ? $vatable * (1 + self::VAT_RATE) - $withholding : $vatable, 2);
    }

    /** Pieces already booked on a due date (cancelled orders free up capacity). */
    /**
     * Finished long enough ago to be out of the way.
     *
     * completed_at is the honest date, but orders finished before that column
     * was filled in fall back to when they were last touched — otherwise the
     * oldest jobs in the shop are the ones that never leave the list.
     */
    public function scopeArchived($query, ?\Carbon\CarbonInterface $before = null)
    {
        $cutoff = $before ?? now()->subDays(self::ARCHIVE_AFTER_DAYS);

        // Both halves are NULL-safe on purpose. `completed_at <= X` against a
        // NULL is not FALSE, it is UNKNOWN — and NOT UNKNOWN is UNKNOWN, so a
        // finished order with no completion date fell out of the list the
        // moment this scope was negated. It has to say IS NOT NULL first.
        return $query->where('status', 'complete')
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->whereNotNull('completed_at')->where('completed_at', '<=', $cutoff))
                ->orWhere(fn ($w) => $w->whereNull('completed_at')->where('updated_at', '<=', $cutoff)));
    }

    /** Is this one off the lists? */
    public function isArchived(): bool
    {
        if ($this->status !== 'complete') {
            return false;
        }

        $when = $this->completed_at ?? $this->updated_at;

        return $when !== null && $when->lte(now()->subDays(self::ARCHIVE_AFTER_DAYS));
    }

    public static function bookedQtyForDate(string $date, ?int $exceptOrderId = null, ?string $productType = null): int
    {
        // Counted per PRODUCT when one is named. Five hundred shirts and five
        // hundred riding jerseys are not the same day's work and do not
        // compete for the same bench, so a date full of shirts must not refuse
        // a jersey. No product named means the whole day, as before.
        return (int) self::whereDate('due_date', $date)
            ->where('status', '!=', 'cancelled')
            ->when($productType, fn ($q) => $q->where('product_type', $productType))
            ->when($exceptOrderId, fn ($q) => $q->where('id', '!=', $exceptOrderId))
            ->sum('quantity');
    }

    public function productLabel(): ?string
    {
        // Known priced product → its config label; otherwise a custom apparel
        // type (e.g. Rash Guard) stored as free text — show it as-is.
        //
        // Looked up in the list the job was priced from: a merch product is
        // not in the standard list, and without this a hybrid jersey came out
        // titled from its key ("Hybrid Riding Jersey Type 1") on every sheet
        // that shows the product.
        return \App\Services\PricingService::label($this->product_type, $this->price_list)
            ?? ($this->product_type ? \Illuminate\Support\Str::title($this->product_type) : null);
    }

    /**
     * Cut the pipeline down to the production run: printer through inventory.
     *
     * Used for a remake. The design steps are already done on the order this
     * one replaces, and there is no sample to show or second release to make —
     * the client bought these pieces once already. What is left is the work of
     * making them again.
     *
     * The steps are deleted rather than cancelled: a cancelled step still
     * shows on the board and in the counts, and a remake that lists eight
     * cancelled design steps reads as a job that went wrong twice.
     */
    public function trimToProductionRun(): void
    {
        $tasks = $this->tasks()->orderBy('sequence')->get();

        $from = $tasks->firstWhere('department', self::MOVER_FIRST_STEP)?->sequence;
        $to = $tasks->firstWhere('department', self::MOVER_LAST_STEP)?->sequence;

        // No production run in this pipeline (nothing is printed, say) — leave
        // it alone rather than deleting every step it has.
        if ($from === null || $to === null || $to < $from) {
            return;
        }

        $this->tasks()
            ->where(fn ($q) => $q->where('sequence', '<', $from)->orWhere('sequence', '>', $to))
            ->delete();

        // Open the first step of what is left, which the design approval would
        // normally have done.
        $this->refresh();
        $firstStage = (int) $this->tasks()->min('stage');
        $this->unlockStage($firstStage);
    }

    /** The order this one is a remake of, if it is one. */
    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_order_id');
    }

    /** Remakes made because of this order. */
    public function replacements(): HasMany
    {
        return $this->hasMany(self::class, 'replaces_order_id');
    }

    /**
     * A remake, not a sale.
     *
     * Worth asking before counting money or capacity: the shop is doing the
     * work twice and being paid once.
     */
    public function isReplacement(): bool
    {
        return $this->replaces_order_id !== null;
    }

    /**
     * May the floor still correct its part of the job order sheet?
     *
     * A seam gets typed against the wrong row, or a thread code is remembered
     * five minutes later. Locking the sheet the moment a station closes means
     * living with the mistake, so it stays open until the whole job order is
     * finished — after that it is a record of what was made, and records do
     * not change.
     */
    public function sheetStillEditable(): bool
    {
        return ! in_array($this->status, ['complete', 'cancelled'], true);
    }

    /** Outstanding balance = total price minus everything paid so far. */
    public function balance(): ?float
    {
        if ($this->total_price === null) {
            return null;
        }

        return max(0, (float) $this->total_price - $this->paidTotal());
    }

    /**
     * Everything paid on this order so far.
     *
     * The order page asks what has been paid, what is left and whether anything
     * has been paid at all, several times over — each was its own SUM. Read the
     * loaded payments when the caller has them, and ask the database only when
     * nobody does.
     */
    private function paidTotal(): float
    {
        return $this->relationLoaded('payments')
            ? (float) $this->payments->sum('amount')
            : (float) $this->payments()->sum('amount');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->orderBy('sequence');
    }

    /**
     * The tech pack — the sheet the floor works the garment from.
     *
     * Made on demand: an order has one from the moment anybody types into it,
     * and asking for it should not depend on remembering to create it first.
     */
    /** Every sheet the order carries, sample and batch both. */
    public function techPacks(): HasMany
    {
        return $this->hasMany(TechPack::class);
    }

    /**
     * The sample sheet.
     *
     * Still called techPack() with no phase because that is what the shop has
     * always meant by "the tech pack", and every pack drawn before the split
     * is a sample. Callers that want the batch sheet ask for it by name.
     */
    public function techPack(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(TechPack::class)->where('phase', TechPack::PHASE_SAMPLE);
    }

    /** The sheet the batch is made from, copied from the sample once the
     *  client has approved it. */
    public function techPackMassprod(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(TechPack::class)->where('phase', TechPack::PHASE_MASSPROD);
    }

    public function techPackOrNew(string $phase = TechPack::PHASE_SAMPLE): TechPack
    {
        return $this->techPackFor($phase) ?? $this->techPacks()->make(['phase' => $phase]);
    }

    /** The saved sheet for one phase, or null when it has not been drawn. */
    public function techPackFor(string $phase): ?TechPack
    {
        return $this->relationLoaded('techPacks')
            ? $this->techPacks->firstWhere('phase', $phase)
            : $this->techPacks()->where('phase', $phase)->first();
    }

    /**
     * The sheet for one phase, ready to be written to.
     *
     * The batch sheet is opened from the approved sample the first time
     * anybody asks for it, rather than at the moment the stage releases. Two
     * reasons: the copy is then taken from the sample as the client finally
     * approved it, corrections and all; and an order whose batch step is
     * cancelled or never reached never grows a second sheet nobody drew.
     *
     * Copying forward is a one-off. Once the batch sheet exists it is the
     * batch's own, and later edits to the sample do not follow it - the sample
     * stays as the record of what the client held and approved.
     */
    public function openTechPack(string $phase = TechPack::PHASE_SAMPLE): TechPack
    {
        if ($existing = $this->techPackFor($phase)) {
            return $existing;
        }

        if ($phase === TechPack::PHASE_MASSPROD && ($sample = $this->techPackFor(TechPack::PHASE_SAMPLE))) {
            $this->unsetRelation('techPacks');

            return TechPack::openMassprodFrom($sample);
        }

        return $this->techPacks()->make(['phase' => $phase]);
    }

    /** The brief this order was written from, when there was one. */
    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    /**
     * Which book of clients this job came out of — META or VIP.
     *
     * The order does not carry the team itself; the brief it was written from
     * does. A job taken straight off the counter has no brief, so the officer
     * who wrote it answers for it instead — they belong to one team or the
     * other, and it is the same answer the brief would have given. Walk-ins
     * written by somebody on neither team stay unlabelled rather than being
     * guessed into a team they are not.
     */
    public function salesTeam(): ?string
    {
        $team = $this->inquiry?->team ?: $this->creator?->team;

        return filled($team) ? strtolower(trim((string) $team)) : null;
    }

    /** Which design this order is making - one of the brief's, or none. */
    public function inquiryDesign(): BelongsTo
    {
        return $this->belongsTo(InquiryDesign::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Who the job is for.
     *
     * customer_name is a COPY, written when the order was taken and again on
     * every edit of that order. The client record it was copied from is shared
     * across all of their orders, so correcting a name on one order — or on the
     * client itself — leaves every other order still showing the old spelling,
     * with nothing on screen to say it is out of date.
     *
     * So the record wins and the copy is only the fallback, for the orders old
     * enough to have no client attached. Whole name, not just the first: half
     * the app was showing "Cecilia" for Cecilia Villanueva.
     */
    public function clientName(): string
    {
        return $this->client?->fullName() ?: (string) $this->customer_name;
    }

    /** Is there a sheet behind this job yet? */
    public function hasSheet(): bool
    {
        if ($this->relationLoaded('jobOrder')) {
            return (bool) $this->jobOrder;
        }

        // withExists('jobOrder') on the list that fetched it, so a page of
        // rows answers this without a query each.
        if (array_key_exists('job_order_exists', $this->attributes)) {
            return (bool) $this->attributes['job_order_exists'];
        }

        return $this->jobOrder()->exists();
    }

    /**
     * Where the job order number on a working list should go.
     *
     * The package - the approved mockup, template, job order and production
     * details - is what somebody clicking a job number off a working list is
     * actually after. The supply desk issuing materials wants the sheet in
     * front of them, not the order's admin page; they were landing on the
     * admin page and hunting for the document from there.
     *
     * An order with no sheet yet has no package to open and that route
     * answers 404, so those keep the old destination. A dead link is worse
     * than a plain one.
     */
    public function sheetUrl(): string
    {
        return $this->hasSheet()
            ? route('orders.package', $this)
            : route('orders.show', $this);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('id');
    }

    /** The conversation everyone on this order shares. */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** Size breakdown ordered the way the size charts read. */
    public function itemsInSizeOrder()
    {
        return $this->items->sortBy(fn ($i) => array_search($i->size, self::SIZES))->values();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function jobOrder()
    {
        return $this->hasOne(JobOrder::class, 'production_order_id');
    }

    /** Client-facing documents (delivery receipt / price quotation). */
    public function documents(): HasMany
    {
        return $this->hasMany(OrderDocument::class);
    }

    public function materialRequests(): HasMany
    {
        return $this->hasMany(MaterialRequest::class)->orderBy('id');
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? strtoupper($this->status);
    }

    /**
     * The layout has been drawn.
     *
     * It is drawn on the inquiry now, before this order existed, so the answer
     * is the stamp carried across at creation. Orders written before that
     * change still have a Layout task, and are read the old way.
     */
    public function layoutReleased(): bool
    {
        if ($this->layout_approved_at !== null) {
            return true;
        }

        return $this->tasks()
            ->where('stage', self::STAGE_LAYOUT)
            ->where('status', '!=', 'todo')
            ->exists();
    }

    /**
     * Every task in the layout stage is complete (client approved the layout).
     *
     * Read from the already-loaded tasks when the caller eager-loaded them —
     * the order list asks this of every row, and going back to the database
     * each time cost a query per row. Same as progress() above.
     */
    public function layoutApproved(): bool
    {
        // Approved back on the inquiry, by the officer, once the client said
        // yes — which is why there is no Layout task to look at.
        if ($this->layout_approved_at !== null) {
            return true;
        }

        $layout = $this->relationLoaded('tasks')
            ? $this->tasks->where('stage', self::STAGE_LAYOUT)
            : $this->tasks()->where('stage', self::STAGE_LAYOUT)->get();

        return $layout->isNotEmpty() && $layout->every(fn ($t) => $t->status === 'complete');
    }

    /** The client, through the account officer, approved the final mockup. */
    public function mockupApproved(): bool
    {
        $mockups = $this->relationLoaded('tasks')
            ? $this->tasks->filter(fn ($task) => str_starts_with($task->department, 'Final mockup'))
            : $this->tasks()->where('department', 'like', 'Final mockup%')->get();

        return $mockups->isNotEmpty() && $mockups->every(fn ($task) => $task->status === 'complete');
    }

    /**
     * Why the tech pack has not opened yet, in words - or null once it has.
     *
     * One answer, in one place, because three screens were each giving their
     * own. The artist's step page said "This step is blocked", which names
     * nobody and offers nothing to do; the pack itself named the downpayment
     * whatever the real reason was; and the board simply showed the step.
     *
     * Worse than the wording: the step could be STARTED. Nothing stopped the
     * Open Tech Pack button flipping it to in_progress, and the pack then
     * refused to open - so the job read as being worked on by an artist who
     * could not see it, and it sat there. Two orders on the live board were
     * in exactly that state when this was written.
     *
     * The order asked here is the order the job actually runs in: the mockup
     * is approved, the downpayment is confirmed, and only then does the
     * account officer send the sheet.
     */
    public function techPackWaitingOn(): ?string
    {
        if ($this->jobOrder?->status === 'sent_to_artist') {
            return null;
        }

        return match (true) {
            ! $this->mockupApproved() => 'the final mockup has not been approved yet',
            ! $this->hasDownpayment() => 'the downpayment has not been collected yet',
            default => 'the account officer has not sent the tech pack yet',
        };
    }

    /**
     * Has anything been paid on this order yet?
     *
     * A list that asks this per row should say so with withExists('payments'),
     * which answers it in the query that fetched the orders. Without that this
     * falls back to asking on its own, which is right for a single order.
     */
    /**
     * Nothing is owed on this job at all.
     *
     * A sponsored sample, or one discounted down to nothing. There is no
     * downpayment coming because there is nothing to pay, and every gate that
     * waits for one — sending the job order, starting the layout, the
     * dashboard's "needs downpayment" list — would have waited forever.
     *
     * Priced at nothing is not the same as not priced yet: an order still
     * saying "For quotation" has no total, is not settled, and must not walk
     * onto the floor unpaid. Read off the column so a list can ask this per
     * row without a query each time.
     */
    public function owesNothing(): bool
    {
        return $this->total_price !== null && (float) $this->total_price <= 0.0;
    }

    /**
     * Does this client pay when they receive the goods?
     *
     * The same tick as the deposit waiver, read at the other end of the job.
     * A client who is trusted to start without money down is the client who
     * pays on delivery - there is no third arrangement in the shop, and asking
     * the officer to say the same thing twice would only mean the second one
     * gets forgotten and the goods are held at the counter over a balance
     * nobody ever intended to collect first.
     *
     * It does NOT mean the money stops mattering: the balance is written onto
     * the order's conversation as the goods go out, so the people who chase it
     * know what to chase.
     */
    public function paysOnDelivery(): bool
    {
        return (bool) $this->downpayment_waived;
    }

    public function hasDownpayment(): bool
    {
        // An account officer can explicitly waive the deposit for a sponsored
        // or trusted-client order. This is an auditable workflow decision, not
        // a made-up payment record.
        if ($this->downpayment_waived) {
            return true;
        }

        // Nothing to wait for. Asked before anything else, because no payment
        // will ever arrive to answer it.
        if ($this->owesNothing()) {
            return true;
        }

        // CONFIRMED money only. What the officer records is what the client
        // says they have sent; Finance watches the account and says whether it
        // arrived. The shop draws on the second answer, not the first.
        if (array_key_exists('payments_exists', $this->attributes)) {
            return (bool) $this->attributes['payments_exists'];
        }

        // The dashboard loads the payments themselves — no need to ask again.
        if ($this->relationLoaded('payments')) {
            return $this->payments->contains(fn ($payment) => $payment->isConfirmed());
        }

        return $this->payments()->whereNotNull('confirmed_at')->exists();
    }

    /**
     * Give every step the date it has to be finished by.
     *
     * The sample and the batch have separate promises. The sample run (layout
     * through presenting the sample) has sampleLeadDays() — three days, or
     * four for a jersey. Only after that does the batch run receive the
     * remaining time through the client's due date.
     *
     * Evenly on purpose. Weighting a cut against a sew is a guess about work
     * nobody has measured, and a wrong weight is worse than an even split
     * because it looks considered.
     *
     * The clock starts when the money is confirmed, not when the order was
     * taken: an order sitting unpaid for a fortnight has not used any of its
     * time. A job already past its due date gets today for everything that is
     * left — it is late, and pretending otherwise helps nobody.
     */
    public function scheduleStepDeadlines(?\Carbon\CarbonInterface $from = null, bool $preserveCompleted = false): int
    {
        if (! $this->due_date) {
            return 0;
        }

        $steps = $this->tasks()->orderBy('sequence')->get();

        if ($preserveCompleted) {
            $steps = $steps->reject(fn (Task $step) => in_array($step->status, ['complete', 'cancelled'], true))->values();
        }

        if ($steps->isEmpty()) {
            return 0;
        }

        $start = $from ?? $this->firstConfirmedPaymentAt() ?? now();
        $end = $this->due_date->copy()->endOfDay();

        // Already late, or due today: everything outstanding is wanted now.
        if ($end->lessThanOrEqualTo($start)) {
            foreach ($steps as $step) {
                $step->update(['due_at' => $end]);
            }

            return $steps->count();
        }

        $assignWindow = static function ($windowSteps, \Carbon\CarbonInterface $windowStart, \Carbon\CarbonInterface $windowEnd): void {
            $count = $windowSteps->count();

            if ($count === 0) {
                return;
            }

            if ($windowEnd->lessThanOrEqualTo($windowStart)) {
                foreach ($windowSteps as $step) {
                    $step->update(['due_at' => $windowEnd]);
                }

                return;
            }

            $each = $windowStart->diffInMinutes($windowEnd) / $count;
            $last = $count - 1;

            foreach ($windowSteps->values() as $i => $step) {
                $step->update([
                    // The last step of each phase lands exactly on the phase
                    // deadline, avoiding a rounding spill into the next day.
                    'due_at' => $i === $last
                        ? $windowEnd
                        : $windowStart->copy()->addMinutes((int) round($each * ($i + 1))),
                ]);
            }
        };

        // Every job runs in two parts, and the first part depends on whether
        // there is a sample to make.
        //
        // Normally it is the sample run: everything up to putting a sample in
        // front of the client. When the sample is skipped that run is not even
        // built - the pipeline goes stage 1, 2, 3, then straight to 10 - and
        // what is left in front is the DESIGN run: the layout, the final
        // mockup and the tech pack. Those are worked on every job, sample or
        // not, and they used to carry no deadline at all because the whole
        // window was handed to mass production. People were doing that work
        // with nothing saying when it was wanted.
        //
        // Stage 3 - the raw materials and the printing - is in the front run
        // either way. It was left out of a skip-sample job at first, on the
        // reading that those belong to the sample and the batch has its own at
        // stage 10. The shop says otherwise: they are worked on a skip-sample
        // job like any other, and the board bears that out - they sit READY on
        // the live ones. So the front run is simply everything before mass
        // production, and the flag only decides which steps exist at all.
        $frontSteps = $steps->filter(
            fn (Task $step) => $step->stage < self::STAGE_MASS_PRODUCTION
        )->values();

        $massProductionSteps = $steps->filter(fn (Task $step) => $step->stage >= self::STAGE_MASS_PRODUCTION)->values();

        if ($frontSteps->isEmpty()) {
            $assignWindow($massProductionSteps, $start, $end);
        } else {
            // Through sampleLeadDays(), not the bare constant: a jersey gets
            // the longer window, and reading the constant here was how the
            // schedule and the jersey rule disagreed. On a skip-sample job the
            // same allowance covers the design run, which is the same few days
            // at the front of the job under a different name.
            $frontEnd = $start->copy()->startOfDay()
                ->addDays($this->sampleLeadDays())
                ->endOfDay();

            // A promised delivery earlier than the normal front window does
            // not move the client deadline; both phases simply become urgent.
            if ($frontEnd->greaterThan($end)) {
                $frontEnd = $end->copy();
            }

            $assignWindow($frontSteps, $start, $frontEnd);
            $assignWindow($massProductionSteps, $frontEnd, $end);
        }

        return $steps->count();
    }

    /**
     * Give a deadline to any step that has none, and touch nothing else.
     *
     * The schedule was written once, at the moment the downpayment cleared,
     * and never again - so anything that changed the pipeline afterwards left
     * steps with no date at all. Three kinds of job ended up like that: a
     * sibling order created under a job number that already had clearance, so
     * the one scheduling moment had already passed; a pipeline rebuilt after a
     * tech pack changed, whose new steps are born blank; and a sponsored job
     * waived rather than paid, which has no payment to confirm and so never
     * reaches that moment at all. Eighty-two steps across the shop, carrying
     * no date, unable to be late.
     *
     * Each blank is placed BETWEEN the dated steps either side of it, rather
     * than at the position the full schedule would have given it. That
     * matters: the first attempt spread them by position and put two steps
     * after the ones that follow them - Pairing due before the cutting it
     * waits on. Interpolating cannot do that, because a gap is only ever
     * filled inside the space its neighbours leave.
     *
     * A run of blanks at the front anchors on the clock's start, and one at
     * the end on the client's due date.
     *
     * Only the blanks. A date somebody typed into the pipeline is the whole
     * point of that box being there, and filling gaps is no reason to argue
     * with it.
     *
     * Does nothing until the job is cleared to run: an order still waiting on
     * its downpayment has not started its clock, and dating it would hand it
     * deadlines to be late against for work nobody has asked for yet.
     */
    public function fillMissingStepDeadlines(): int
    {
        if (! $this->hasDownpayment() || ! $this->due_date) {
            return 0;
        }

        $steps = $this->tasks()
            ->where('status', '!=', 'cancelled')
            ->get()
            ->sortBy([['stage', 'asc'], ['sequence', 'asc']])
            ->values();

        if ($steps->isEmpty() || $steps->every(fn (Task $step) => (bool) $step->due_at)) {
            return 0;
        }

        $start = $this->firstConfirmedPaymentAt() ?? $this->created_at ?? now();
        $end = $this->due_date->copy()->endOfDay();

        if ($end->lessThanOrEqualTo($start)) {
            $end = $start->copy()->endOfDay();
        }

        $filled = 0;
        $i = 0;
        $count = $steps->count();

        while ($i < $count) {
            if ($steps[$i]->due_at) {
                $i++;

                continue;
            }

            // The whole run of blanks, and the dated steps bracketing it.
            $j = $i;
            while ($j < $count && ! $steps[$j]->due_at) {
                $j++;
            }

            $from = $i > 0 ? $steps[$i - 1]->due_at->copy() : $start->copy();
            $to = $j < $count ? $steps[$j]->due_at->copy() : $end->copy();

            // Neighbours already out of order, or no room between them: the
            // blanks land on the later of the two rather than before the step
            // they follow.
            if ($to->lessThanOrEqualTo($from)) {
                $to = $from->copy()->addMinute();
            }

            $gap = $j - $i;
            $each = $from->diffInMinutes($to) / ($gap + 1);

            for ($k = 0; $k < $gap; $k++) {
                $steps[$i + $k]->update([
                    'due_at' => $from->copy()->addMinutes((int) round($each * ($k + 1))),
                ]);
                $filled++;
            }

            $i = $j;
        }

        return $filled;
    }

    /**
     * Print type decides the default press and the cutting route.
     *
     * This used to sit in the artist's save, because the artist was the only
     * one who could set a print type. The account officer sets it on the tech
     * pack header now, so the routing has to follow from either desk - set
     * from the header and left here, the job would run the wrong press.
     *
     * Does nothing once production has started: canEditRouting() is the guard
     * that stops a late print type reshuffling steps people are stood at.
     */
    public function applyPrintTypeRouting(): void
    {
        $jobOrder = $this->jobOrder;

        if (! $jobOrder || ! $this->canEditRouting()) {
            return;
        }

        $config = JobOrder::printTypeConfig($jobOrder->fresh()->print_type);

        if (! $this->cutting_type && ($config['cutting'] ?? null)) {
            $this->update(['cutting_type' => $config['cutting']]);
        }

        // The printer, the same way and for the same reason. It was the one
        // routing field this method left alone, so a pack could reach the
        // artist with the box empty - and the box is one of the seventeen
        // that must be answered before the pack can be submitted, so the job
        // stopped on a question nobody had been asked. An imported pack made
        // it worse: that page hides the whole sheet, so there was nowhere on
        // it to answer.
        //
        // Only when empty. Whatever the officer chose stays chosen.
        if (! $jobOrder->fresh()->printer) {
            $jobOrder->update(['printer' => $jobOrder->fresh()->defaultPrinter()]);
        }

        if (! $jobOrder->fresh()->fabric_press) {
            $fabricPress = $jobOrder->fresh()->defaultFabricPress();
            $jobOrder->update([
                'fabric_press' => $fabricPress,
                'needs_embroidery' => $fabricPress === 'embroidery'
                    ? true
                    : (bool) $jobOrder->needs_embroidery,
            ]);
        }

        $this->refresh()->rebuildPipeline($this->decoration_methods ?? [], $this->cutting_type);
    }

    /**
     * How many days this order's sample gets before batch work begins.
     *
     * Three for most things, four for a jersey. The jersey allowance was
     * written down as a constant and then never asked for — every order,
     * panelled or not, got the flat three — which is exactly the "late by
     * design" the constant was added to prevent.
     */
    public function sampleLeadDays(): int
    {
        return in_array($this->product_type, self::JERSEY_PRODUCT_TYPES, true)
            ? self::SAMPLE_LEAD_DAYS_JERSEY
            : self::SAMPLE_LEAD_DAYS;
    }

    /**
     * When the sample is wanted, or null when it is not wanted at all.
     *
     * The clock starts at the confirmed payment, the same moment the step
     * deadlines start from: an order sitting unpaid has not used any of its
     * time. An order set to skip the sample has no sample to be late for.
     */
    public function computeSampleDueDate(): ?\Carbon\CarbonInterface
    {
        if ($this->skip_sample) {
            return null;
        }

        // The clock has to have started at all.
        //
        // Steps can end up dated on a job that was never cleared to run:
        // scheduleStepDeadlines() falls back to now() when there is no
        // confirmed payment, and something reached it. Reading those back
        // would give the job a sample deadline for a clock that never
        // started, which is the fault sampleOverdue() was written to avoid.
        // hasDownpayment() is the same question the rest of the pipeline
        // asks, and it counts a waived deposit and a job that owes nothing.
        if (! $this->hasDownpayment()) {
            return null;
        }

        // The pipeline's own answer, when it has one.
        //
        // These were two formulas for one date, and two formulas drift: this
        // one read the bare constant once while the schedule read the method,
        // and a jersey's badge and its steps disagreed by a day. They agree by
        // construction now - the schedule pins its last sample step to exactly
        // the date below - so reading it back changes nothing in the ordinary
        // case and settles the two places they could still part company.
        //
        // A rush job, where the client's due date lands inside the sample
        // window: the schedule clamps its steps to the client deadline and
        // this did not, so the badge sat AFTER the promise and the sample was
        // never called late until the whole job already was.
        //
        // And a deadline somebody moved by hand. A leader who pushes the last
        // sample step back has said when the sample is wanted; the badge
        // saying otherwise is the system arguing with the person who owns the
        // decision.
        if ($fromPipeline = $this->lastSampleStepDueAt()) {
            return $fromPipeline;
        }

        $start = $this->firstConfirmedPaymentAt();

        return $start
            ? $start->copy()->startOfDay()->addDays($this->sampleLeadDays())
            : null;
    }

    /**
     * When the last step of the sample run is wanted, if it has been dated.
     *
     * Undated until the schedule runs, which is the same moment the sample
     * date exists at all - both are written when the first payment is
     * confirmed - so in practice this answers whenever there is anything to
     * answer. It falls back rather than failing because an order with no
     * client due date gets no step dates at all: scheduleStepDeadlines()
     * gives up without one, and the sample still has a promise of its own.
     *
     * Read off the loaded tasks when they are there. The only page that asks
     * loads them already, and asking again per order is how a list page turns
     * into a query per row.
     */
    private function lastSampleStepDueAt(): ?\Carbon\CarbonInterface
    {
        if ($this->relationLoaded('tasks')) {
            return $this->tasks
                ->filter(fn (Task $step) => $step->stage < self::STAGE_MASS_PRODUCTION
                    && $step->due_at
                    && $step->status !== 'cancelled')
                ->max('due_at');
        }

        // max() and not orderByDesc()->value(): the relation carries its own
        // orderBy('sequence'), an order added here is APPENDED to that rather
        // than replacing it, and sequence wins - so asking for the latest
        // quietly returned the first step of the sample run instead of the
        // last. An aggregate has no such argument with the ordering.
        //
        // The status test is written long because `!= 'cancelled'` is false
        // for a row with no status at all, which would drop steps the
        // collection branch above keeps.
        $latest = $this->tasks()
            ->where('stage', '<', self::STAGE_MASS_PRODUCTION)
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'cancelled'))
            ->max('due_at');

        return $latest ? \Illuminate\Support\Carbon::parse($latest) : null;
    }

    /** Works out the sample date and writes it down, when it has changed. */
    public function applySampleDueDate(): ?\Carbon\CarbonInterface
    {
        $due = $this->computeSampleDueDate();

        if (optional($due)->toDateString() !== optional($this->sample_due_date)->toDateString()) {
            $this->forceFill(['sample_due_date' => $due])->save();
        }

        return $due;
    }

    /**
     * True when the sample is wanted and the day has passed.
     *
     * Worked out rather than read off the column. sample_due_date is only
     * rewritten when a payment is confirmed, so an order whose payment went
     * away keeps the date it had — and would have been called late for a
     * clock that never started running.
     */
    public function sampleOverdue(): bool
    {
        $due = $this->computeSampleDueDate();

        return $due !== null
            && $this->status === 'active'
            && $due->copy()->endOfDay()->isPast();
    }

    /** When the first payment was confirmed — the moment the job starts. */
    public function firstConfirmedPaymentAt(): ?\Carbon\CarbonInterface
    {
        // ->value() hands back the raw column, not a date: ask for the row so
        // the model's own cast does the work.
        return $this->payments()
            ->whereNotNull('confirmed_at')
            ->orderBy('confirmed_at')
            ->first()?->confirmed_at;
    }

    /** Money recorded but not yet confirmed by Finance. */
    public function hasPaymentAwaitingFinance(): bool
    {
        if ($this->relationLoaded('payments')) {
            return $this->payments->contains(fn ($payment) => ! $payment->isConfirmed());
        }

        return $this->payments()->whereNull('confirmed_at')->exists();
    }

    /**
     * Is the order settled in full?
     *
     * Nothing leaves the shop on an unpaid balance, so this gates the release
     * step. An order with no price set yet has nothing to settle against and
     * can't be judged either way — treat that as not paid rather than guess,
     * because guessing wrong hands over goods for free.
     */
    public function isFullyPaid(): bool
    {
        if ($this->total_price === null) {
            return false;
        }

        // A hair under, from rounding a split payment, is paid.
        return $this->paidTotal() >= (float) $this->total_price - 0.005;
    }

    public function totalPaid(): string
    {
        return number_format($this->paidTotal(), 2);
    }

    /** @return array{0: int, 1: int} completed tasks, total tasks */
    public function progress(): array
    {
        $tasks = $this->tasks;

        return [$tasks->where('status', 'complete')->count(), $tasks->count()];
    }

    /**
     * The mover's slice of a job: the shop floor, from the printer through to
     * the finished pieces being counted in.
     *
     * She follows work through the machines. What happens before the printer is
     * the account officer's and the artist's — the enquiry, the layout, the
     * mockup, the leader's sign-off — and what happens after inventory is the
     * account officer handing over to the client. Neither is hers to chase.
     */
    public const MOVER_FIRST_STEP = 'Printer';

    public const MOVER_LAST_STEP = 'Inventory';

    /**
     * May this person open this job order?
     *
     * The calendar shows every job to everybody — it is the company's capacity
     * in one view, and hiding half of it makes the picture a lie. Opening one
     * is different: leaders, supervisors and admin can open any, everyone else
     * only the jobs that are theirs.
     *
     * "Theirs" means what it means for that role: the officer who took the
     * order, an artist or operator with a step on it, and for the mover a job
     * that has reached the floor.
     */
    public function openableBy(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        // isLeader() already covers the supervisor and the super admin.
        if ($user->isLeader()) {
            return true;
        }

        if ($user->isSales()) {
            return $this->created_by === $user->id;
        }

        if ($user->isMover()) {
            return $this->reachedTheFloorAt() !== null;
        }

        return $this->tasks->contains('assigned_to', $user->id);
    }

    /**
     * A delivered or cancelled job is finished business — its thread stays
     * readable as a record of what happened, but nothing more is added to it.
     */
    public function conversationClosed(): bool
    {
        return in_array($this->status, ['complete', 'cancelled'], true);
    }

    /**
     * The steps a given person is shown on this order. Everyone sees the whole
     * pipeline except the mover, who sees her slice of it.
     *
     * The slice is taken by POSITION in the line, not by stage number: the
     * artist's export sits in the same stage as the printer but comes before
     * it, and it is not hers.
     */
    public function stepsVisibleTo(?User $user)
    {
        $tasks = $this->tasks->sortBy([['stage', 'asc'], ['sequence', 'asc']])->values();

        if (! $user?->isMover()) {
            return $tasks;
        }

        $from = $tasks->search(fn ($t) => $t->department === self::MOVER_FIRST_STEP);
        $to = $tasks->search(fn ($t) => $t->department === self::MOVER_LAST_STEP);

        if ($from === false || $to === false || $to < $from) {
            return $tasks->take(0);
        }

        return $tasks->slice($from, $to - $from + 1)->values();
    }

    /**
     * When this job reached the printer — the moment it became the mover's to
     * follow. Null while it is still with the artist or the account officer.
     */
    public function reachedTheFloorAt(): ?\Illuminate\Support\Carbon
    {
        return $this->tasks->firstWhere('department', self::MOVER_FIRST_STEP)?->released_at;
    }

    /** When the finished pieces were counted in, closing her slice. */
    public function leftTheFloorAt(): ?\Illuminate\Support\Carbon
    {
        $inventory = $this->tasks->firstWhere('department', self::MOVER_LAST_STEP);

        return $inventory?->status === 'complete' ? $inventory->approved_at : null;
    }

    /**
     * The movers who followed this job, in the order they first spoke.
     *
     * They close no step, so there is no operator name to read off one — what
     * says a mover was on this job is that they wrote on it, and because the
     * login is shared each of them signs with their own name.
     */
    public function moverNames(): string
    {
        return $this->messages()
            ->whereNotNull('sender_name')
            ->whereHas('sender', fn ($q) => $q->whereRaw('LOWER(TRIM(job_role)) = ?', ['mover']))
            ->orderBy('id')
            ->pluck('sender_name')
            ->unique()
            ->join(', ');
    }

    /* ==================== Running late ==================== */

    /** Nothing can be late once it's finished, cancelled or paused. */
    public function chasesDeadline(): bool
    {
        return $this->status === 'active' && $this->due_date !== null;
    }

    /**
     * How this job stands against its due date:
     *   'delayed'  — the day has passed and it still isn't out the door
     *   'at_risk'  — it's due TODAY and still on the floor
     *   null       — nothing to worry about
     */
    public function delayState(): ?string
    {
        if (! $this->chasesDeadline()) {
            return null;
        }

        $due = $this->due_date->copy()->startOfDay();
        $today = now()->startOfDay();

        if ($due->lt($today)) {
            return 'delayed';
        }

        return $due->eq($today) ? 'at_risk' : null;
    }

    /** Plain wording for the banner. */
    public function delayLabel(): ?string
    {
        return match ($this->delayState()) {
            'delayed' => 'PROJECT DELAYED',
            'at_risk' => 'PROJECT MAY BE DELAYED',
            default => null,
        };
    }

    /** How many days past due, for spelling out how bad it is. */
    public function daysLate(): int
    {
        if ($this->delayState() !== 'delayed') {
            return 0;
        }

        return (int) $this->due_date->copy()->startOfDay()->diffInDays(now()->startOfDay());
    }

    /**
     * The step the job is sitting on right now — the earliest one that has been
     * released to somebody and isn't finished. Null when nothing is moving.
     */
    public function currentStep(): ?Task
    {
        return $this->tasks
            ->whereIn('status', ['ready', 'in_progress', 'for_checking', 'revision_required'])
            ->sortBy([['stage', 'asc'], ['sequence', 'asc']])
            ->first();
    }

    /**
     * What picks the job up once the current step is signed off. Looks past
     * everything still open at the current step, so it names the genuine next
     * stop rather than a sibling running alongside.
     */
    public function nextStep(): ?Task
    {
        $open = $this->tasks
            ->whereNotIn('status', ['complete', 'cancelled'])
            ->sortBy([['stage', 'asc'], ['sequence', 'asc']]);

        $current = $this->currentStep();

        // Nothing released yet — whatever is first in the queue is what's next.
        if (! $current) {
            return $open->first();
        }

        // Steps sharing the current stage run alongside it, not after it.
        return $open->first(fn ($t) => $t->stage > $current->stage);
    }

    /** Where the job is, in words the office uses. */
    public function currentStepLabel(): string
    {
        $step = $this->currentStep();

        if (! $step) {
            return $this->status === 'complete' ? 'Finished' : 'Not started';
        }

        return $step->department;
    }

    /** What happens after the current step, or null when this is the last one. */
    public function nextStepLabel(): ?string
    {
        return $this->nextStep()?->department;
    }

    /* ==================== Pipeline construction ==================== */

    /**
     * Build the full job pipeline as staged tasks. A task becomes READY when
     * every task in the previous populated stage is complete; tasks that share
     * a stage run in parallel. Only the chosen decoration/cutting tasks exist.
     *
     * @param  array<int, string>  $decorationMethods  keys from DECORATION_METHODS
     */
    public static function createJobOrder(array $attributes, array $decorationMethods = [], ?string $cuttingType = null): self
    {
        return DB::transaction(function () use ($attributes, $decorationMethods, $cuttingType) {
            // Use the number the account officer typed; fall back to a suggestion.
            $attributes['order_number'] = $attributes['order_number'] ?? self::nextOrderNumber();
            $attributes['decoration_methods'] = $decorationMethods;
            $attributes['cutting_type'] = $cuttingType;

            $order = self::create($attributes);
            $order->buildPipeline($decorationMethods, $cuttingType);

            // A job that is ALREADY cleared to run gets its dates now, because
            // the moment that normally writes them has been and gone. That is
            // the sibling order written under a job number whose downpayment
            // cleared weeks ago, and the sponsored job whose deposit is
            // waived: both were created with a due date and no schedule, and
            // nothing was ever going to come back for them.
            $order->refresh()->fillMissingStepDeadlines();

            return $order->refresh();
        });
    }

    /**
     * Which job_role actually handles each department, so work is assigned to the
     * specific person (Raw materials → Raw materials, Printer/Sticker → Printer,
     * Inventory → Raw materials, etc.) rather than the broad team. Falls back to
     * the broad team when that role has no active user.
     */
    public const DEPARTMENT_ROLES = [
        'Raw materials' => 'Raw materials',
        'Printer' => 'Printer',
        'Sticker' => 'Printer',
        'Inventory' => 'Inventory',
        'Embroidery' => 'Embroidery',
        'Small press' => 'Small Press',
        'Roller press' => 'Roller Press',
        'Manual cutting' => 'Laser Cutting',
        'Laser cutting' => 'Laser Cutting',
        'Pairing' => 'Pairing',
        'Sewing' => 'Sewing',
        'Quality control' => 'Quality Control',
    ];

    /**
     * Steps that stay LOCKED until other step(s) IN THE SAME STAGE finish. A
     * value can be a single department or a list — the step waits for ALL of
     * them. The press waits for the print AND the fabric; embroidery is done
     * after the garment is sewn.
     */
    public const STEP_PREREQUISITES = [
        // The pack is built FROM the approved design, so it cannot start until
        // the client has approved the mockup. Both are stage 2, and
        // prerequisites are matched within a stage.
        //
        // The printer and the sticker station used to wait on an Export step.
        // They now wait on nothing within their stage: stage 3 only opens once
        // the leader has approved the tech pack, and the pack carries the file
        // location the printer opens.
        'Tech pack' => 'Final mockup',
        // The batch is printed from the batch sheet, so the sheet is drawn and
        // signed off first. Both are stage 10.
        'Mass production' => self::STEP_TECH_PACK_MASSPROD,
        'Embroidery' => 'Sewing',
        // The press can't run until the print is ready (Printer) AND the fabric
        // has been issued (Raw materials) — you press the transfer onto the cloth.
        //
        // 'Mass production' is in the list for the batch run, where it is the
        // step that does the printing. Prerequisites are matched WITHIN a stage
        // and a missing one is treated as met, so each run only ever waits for
        // the steps it actually has: Printer + Raw materials on the sample,
        // Mass production on the batch.
        'Small press' => ['Printer', 'Raw materials', 'Mass production'],
        'Roller press' => ['Printer', 'Raw materials', 'Mass production'],
    ];

    /**
     * True when every same-stage prerequisite for a department is complete (or the
     * prerequisite step doesn't exist for this order). Supports a single prereq or
     * a list — the step waits for ALL of them.
     *
     * @param  \Illuminate\Support\Collection<int, Task>  $stageTasks
     */
    private static function prerequisitesMet(string $department, $stageTasks): bool
    {
        foreach ((array) (self::STEP_PREREQUISITES[$department] ?? []) as $prereq) {
            $prereqTask = $stageTasks->firstWhere('department', $prereq);
            if ($prereqTask && ! in_array($prereqTask->status, ['complete', 'cancelled'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve a department to its specific role, else the broad fallback team.
     *
     * Matched without regard to case, and the role is returned SPELLED THE WAY
     * THE STAFF LIST SPELLS IT. Job roles are typed by hand, so the desk that
     * issues materials is "Raw Materials" on one account and "Raw materials"
     * here — an exact match missed, the step fell back to the whole supply
     * chain, and the board named somebody who does not do that job.
     */
    public static function teamFor(string $department, ?string $fallback, array $activeRoles): ?string
    {
        $role = self::DEPARTMENT_ROLES[$department] ?? null;

        if ($role === null) {
            return $fallback;
        }

        foreach ($activeRoles as $active) {
            if (is_string($active) && strcasecmp($active, $role) === 0) {
                return $active;
            }
        }

        return $fallback;
    }

    /**
     * A task-creating closure that keeps numbering the sequence where it left off.
     */
    private function taskAdder(int &$seq): callable
    {
        $activeRoles = User::where('is_active', true)->distinct()->pluck('job_role')->all();

        return function (int $stage, string $department, ?string $team, string $approver = 'leader', bool $autoSubmit = false) use (&$seq, $activeRoles) {
            $team = self::teamFor($department, $team, $activeRoles);

            $this->tasks()->create([
                'sequence' => ++$seq,
                'stage' => $stage,
                'department' => $department,
                'team' => $team,
                'status' => 'todo',
                'approver_role' => $approver,
                'auto_assign' => $team !== null,
                'auto_submit' => $autoSubmit,
            ]);
        };
    }

    /** Create the full staged task list for this order (all TODO).
     *
     * Stages 1-2 are the artist's design steps, worked from "My Tasks".
     * Stages 3-11 are the production line: they exist as tasks so the station
     * board knows what has reached each machine, but the floor works them from
     * the Station board rather than a task list.
     */
    public function buildPipeline(array $decorationMethods, ?string $cuttingType): void
    {
        $seq = 0;
        $add = $this->taskAdder($seq);

        $artist = User::JOB_ARTIST;

        // 1 — Layout: artist work, the CLIENT decides (via sales).
        $add(1, 'Layout', $artist, 'sales');

        // 2 — the mockup goes to the client via sales. The artist's completed
        // Tech Pack then goes to that same account officer first; their approval
        // forwards it to the leader for the final production sign-off.
        $add(2, 'Final mockup', $artist, 'sales');
        $add(2, 'Tech pack', $artist, 'sales');

        $this->addProductionStages($decorationMethods, $cuttingType, $seq);
    }

    /**
     * The decoration steps for this order, in the order they happen: the press
     * first (defaulted from the print type, overridable on the production-details
     * page), then embroidery if the job order asks for it.
     *
     * @param  array<int, string>  $legacyMethods  older orders stored these on the order itself
     * @return array<int, string>  department labels
     */
    public function decorationSteps(array $legacyMethods = []): array
    {
        $jo = $this->jobOrder;
        $steps = [];

        // The fabric-merge press runs on the normal press stations, by its type.
        $fabric = $this->fabricPressType();
        if ($fabric) {
            $steps[] = self::DECORATION_METHODS[$fabric] ?? ucfirst(str_replace('_', ' ', $fabric));
        }

        // The DECORATION press (explicit choice). If it's the same press type as
        // the fabric press, it's the same station step — don't duplicate it.
        $press = $jo?->press;
        if ($press && $press !== 'embroidery') {
            $label = self::DECORATION_METHODS[$press] ?? ucfirst(str_replace('_', ' ', $press));
            if (! in_array($label, $steps, true)) {
                $steps[] = $label;
            }
        }

        if ($jo?->needs_embroidery) {
            $steps[] = self::DECORATION_METHODS['embroidery'];
        }

        // Anything an older order recorded directly still counts.
        foreach ($legacyMethods as $method) {
            $label = self::DECORATION_METHODS[$method] ?? ucfirst(str_replace('_', ' ', $method));

            if (! in_array($label, $steps, true)) {
                $steps[] = $label;
            }
        }

        return $steps;
    }

    /**
     * The fabric-press TYPE for this order (heat/roller/… key) when there is a
     * real press that merges the print onto the fabric. Null when the fabric
     * press is embroidery or not set — no fabric-press step is created then.
     */
    public function fabricPressType(): ?string
    {
        $fp = $this->jobOrder?->fabric_press;

        return ($fp && $fp !== 'embroidery') ? $fp : null;
    }

    /**
     * Stages 3-11 — the production line. Split out so the routing can be rebuilt
     * when the cutting/decoration choice changes.
     */
    private function addProductionStages(array $decorationMethods, ?string $cuttingType, int $seq = 0): void
    {
        $add = $this->taskAdder($seq);

        $supply = User::JOB_SUPPLY_CHAIN;
        $prod = User::JOB_PRODUCTION;
        $artist = User::JOB_ARTIST;

        // 3-11 — the production line. These ARE tasks: the station board only
        // offers an order once its matching task is released (see
        // StationController::eligibleOrders), and the order only completes once
        // they are all done. Workers don't open them from "My Tasks" — they run
        // them at their station, and finishing there completes the task.

        // There is no separate Export step any more. Where the print-ready
        // files were saved is recorded on the TECH PACK, in its file location
        // panel — the same sheet the floor already reads. A step whose whole
        // job was to carry one path is a step that only ever held the printer
        // up waiting for someone to close it.

        // 3 — what happens the moment the leader signs off the tech pack, in the
        // order the shop does it: the fabric is issued, the design is printed,
        // it is pressed onto the cloth, and the sticker is run if the job has
        // one. The press used to be created after the sticker, which put the
        // board out of step with the floor.
        $add(3, 'Raw materials', $supply);
        $add(3, 'Printer', $supply);

        // The press is gated on the Printer AND Raw materials (see
        // STEP_PREREQUISITES) — you press the transfer onto cloth, so both have
        // to be there. Embroidery is NOT here: it runs on the sewn garment.
        foreach ($this->decorationSteps($decorationMethods) as $label) {
            if ($label === self::DECORATION_METHODS['embroidery']) {
                continue;
            }
            $add(3, $label, $prod);
        }

        // Only when the client ordered one.
        if ($this->needs_sticker) {
            $add(3, 'Sticker', $supply);
        }

        // Stages 5-9 are the SAMPLE run: one piece is cut, paired, sewn, QC'd and
        // shown to the client for approval before the rest is made. When the order
        // is set to skip the sample, this whole phase is dropped and production
        // goes straight to Mass production (stage 10).
        if (! $this->skip_sample) {
            // 5 — cutting, laser or manual per the job order.
            if ($cuttingType) {
                $add(5, self::CUTTING_TYPES[$cuttingType] ?? 'Cutting', $prod);
            }

            // 6-8 — the production line. Embroidery runs on the sewn garment (after
            // sewing, before QC); the embroidery-hold logic keeps it from starting
            // until sewing is finished.
            $add(6, 'Pairing', $prod);
            $add(7, 'Sewing', $prod);
            if ($this->jobOrder?->needs_embroidery) {
                $add(7, self::DECORATION_METHODS['embroidery'], $prod);
            }
            $add(8, 'Quality control', $prod);

            // 9 — once QC passes, the first sample is for the account officer to
            // show the client. Nobody "works" this step, so it lands straight on
            // Sample Review rather than waiting at a station for someone to
            // close it — carrying it across the room isn't a system step.
            $add(9, 'Produce sample for client', $prod, 'sales', true);
        }

        // 10 — the batch sheet, then mass production.
        //
        // The batch is a different garment from the sample: it is what the
        // client's corrections turned the sample into. Drawn on the sample's
        // own sheet, approving the batch overwrote the only record of what the
        // client actually approved — and the floor pressing the batch was
        // reading a sheet that had been edited after they last looked at it.
        //
        // So the batch gets a sheet of its own, opened here, copied forward
        // from the approved sample and corrected rather than retyped. It
        // travels the same road as the sample sheet: artist, then account
        // officer, then leader.
        //
        // An order that skips the sample never had two garments — its one
        // sheet is the one it has been filling in all along.
        if (! $this->skip_sample) {
            $add(10, self::STEP_TECH_PACK_MASSPROD, $artist, 'sales');
        }

        // Prints the whole batch; the entire order when the sample was skipped.
        $add(10, 'Mass production', $prod);

        // The batch has to be PRESSED too. Printing it is only half of it — the
        // transfer still has to go onto the cloth before anything can be cut,
        // and the line was written as "the same line the sample did" while
        // quietly leaving this out of the copy. The shop pressed the batch
        // anyway, off the books, so it was never timed and never showed up as
        // the thing holding an order up.
        //
        // Same stage as Mass production, gated on it, exactly as the sample's
        // press sits with the Printer. Embroidery is not here for the same
        // reason it is not at stage 3: it runs on the sewn garment.
        foreach ($this->decorationSteps($decorationMethods) as $label) {
            if ($label === self::DECORATION_METHODS['embroidery']) {
                continue;
            }
            $add(10, $label, $prod);
        }

        // 11-14 — the rest of the batch goes through the same line the sample did.
        // Printing it is not the end: it still has to be cut, paired, sewn and
        // checked before anything reaches inventory.
        if ($cuttingType) {
            $add(11, self::CUTTING_TYPES[$cuttingType] ?? 'Cutting', $prod);
        }

        $add(12, 'Pairing', $prod);
        $add(13, 'Sewing', $prod);
        if ($this->jobOrder?->needs_embroidery) {
            $add(13, self::DECORATION_METHODS['embroidery'], $prod);
        }
        $add(14, 'Quality control', $prod);

        // 15 — counted into finished goods by the inventory desk.
        $add(15, 'Inventory', $prod);

        // 16 — the finished-products desk hands the goods over and confirms it.
        // They are the ones holding the stock and facing the client at the
        // counter; the account officer never touches the boxes. Nobody "works"
        // this step, so it lands on their page the moment stock is counted in
        // (auto_submit) and the order closes when they confirm.
        $add(16, 'Release to client', null, 'inventory', true);
    }

    /**
     * Routing (decoration/cutting) may change until real PRODUCTION work starts.
     * The design steps (layout/mockup/template, stages 1-2) finish early in the
     * flow — their completion must NOT lock the routing.
     */
    /**
     * May the CUTTING method still be changed?
     *
     * Asked separately from canEditRouting(), which answers for the decoration
     * and the press as well. A finished press used to lock the cutting choice
     * too, and the officer was told "cutting has already been done on this
     * order" while the order sat AT cutting with nothing cut - the press runs
     * before cutting and does not depend on it, which is the same reasoning
     * that already excluded Raw materials and the printer.
     *
     * Only cutting itself locks cutting: once somebody has started cutting the
     * cloth, how it is cut is settled.
     */
    public function canEditCutting(): bool
    {
        return ! $this->tasks()
            ->whereIn('stage', [5, 11])
            ->whereIn('status', ['in_progress', 'for_checking', 'complete'])
            ->exists();
    }

    /**
     * Swap the cutting steps for a different method.
     *
     * Narrower than rebuildPipeline(), on purpose: that one tears down stages
     * 4, 5 and 11 and lays the decoration steps again, which cannot be done
     * once a press has run. This touches the cutting steps and nothing else,
     * and only the ones nobody has started.
     */
    public function changeCuttingTo(?string $cuttingType): void
    {
        if (! $this->canEditCutting()) {
            return;
        }

        $this->tasks()
            ->whereIn('stage', [5, 11])
            ->whereIn('status', ['todo', 'ready'])
            ->delete();

        $this->update(['cutting_type' => $cuttingType]);

        if ($cuttingType) {
            $label = self::CUTTING_TYPES[$cuttingType] ?? 'Cutting';
            $seq = (int) $this->tasks()->max('sequence');
            $add = $this->taskAdder($seq);

            // The sample run only exists when the order has one.
            if (! $this->skip_sample) {
                $add(5, $label, User::JOB_PRODUCTION);
            }

            $add(11, $label, User::JOB_PRODUCTION);
        }

        $this->resequenceTasks();
        $this->refresh()->releaseNextReadyStage();
        // Steps added here are born with no deadline, and the one scheduling
        // moment is long past by the time a tech pack changes.
        $this->refresh()->fillMissingStepDeadlines();
    }

    public function canEditRouting(): bool
    {
        // Only the routing steps themselves lock the choice: decoration (4) and
        // cutting (5). Raw materials or printing finishing must NOT freeze the
        // cutting method — those steps happen before cutting and don't depend
        // on it.
        $presses = ['Cap press', 'Heat press', 'Small press', 'Roller press'];

        return ! $this->tasks()
            ->where(function ($q) use ($presses) {
                // Cutting (5/11) and the press (now a stage-3 step) lock the choice.
                $q->whereIn('stage', [4, 5, 11])
                    ->orWhere(fn ($p) => $p->where('stage', 3)->whereIn('department', $presses));
            })
            ->whereIn('status', ['in_progress', 'for_checking', 'complete'])
            ->exists();
    }

    /**
     * Apply new decoration / cutting / sticker to the pipeline (now station-based).
     *
     * SIMPLIFIED: With the new station-based workflow, decoration/cutting/sticker
     * selection is stored on the job order but doesn't create tasks anymore.
     * The stations handle this work directly.
     */
    public function rebuildPipeline(array $decorationMethods, ?string $cuttingType): void
    {
        // Only safe while no production work has started. Callers check this too,
        // but never risk deleting a stage someone has already worked.
        if (! $this->canEditRouting()) {
            return;
        }

        if (! $this->tasks()->where('stage', '>=', 3)->exists()) {
            // Nothing built yet — lay down the whole production line.
            $this->addProductionStages($decorationMethods, $cuttingType, (int) $this->tasks()->max('sequence'));
        } else {
            // Swap only the routing steps. Raw materials, printing and everything
            // from pairing onwards keep whatever progress they already have.
            $presses = ['Cap press', 'Heat press', 'Small press', 'Roller press'];
            $this->tasks()->whereIn('stage', [4, 5, 11])->delete();
            // A press runs against each print: stage 3 for the sample, stage 10
            // for the batch. Drop the old ones (only if not started) so the new
            // choices replace them.
            $this->tasks()->whereIn('stage', [3, 10])->whereIn('department', $presses)->where('status', 'todo')->delete();

            $seq = (int) $this->tasks()->max('sequence');
            $add = $this->taskAdder($seq);
            $prod = User::JOB_PRODUCTION;

            foreach ($this->decorationSteps($decorationMethods) as $label) {
                if ($label === self::DECORATION_METHODS['embroidery']) {
                    continue;   // embroidery lives with sewing (stages 7/13), synced below
                }
                $add(3, $label, $prod);    // sample: gated on the Printer
                $add(10, $label, $prod);   // batch: gated on Mass production
            }

            if ($cuttingType) {
                // The stage-5 cutting is part of the sample run — skip it when the
                // order has no sample. The batch cutting (stage 11) always runs.
                if (! $this->skip_sample) {
                    $add(5, self::CUTTING_TYPES[$cuttingType] ?? 'Cutting', $prod);
                }
                $add(11, self::CUTTING_TYPES[$cuttingType] ?? 'Cutting', $prod);
            }

            $this->syncStickerStep();
            $this->syncEmbroideryStep();
            $this->resequenceTasks();
        }

        // Whatever this just added was born with no deadline, and the one
        // moment that writes the schedule is long past by the time somebody
        // changes a tech pack. Only the blanks - a date already in the
        // pipeline stays exactly as it is.
        $this->refresh()->fillMissingStepDeadlines();

        $this->releaseNextReadyStage();
    }

    /**
     * The free-logo sticker is a stage-3 step, so the routing rebuild (which only
     * touches 4/5/11) would never add or drop it. Keep it in step here — but
     * never remove one that has already been worked.
     */
    /**
     * Does this name a sticker?
     *
     * A blank field means no sticker, and so does a placeholder standing in for
     * one — somebody typing "n/a" is saying there is none, not ordering one
     * called N/A. Kept here so the answer is the same whoever is filling the
     * row in: the officer on the order, or the artist on the pack.
     */
    public static function namesASticker(?string $value): bool
    {
        $said = trim((string) $value);

        if ($said === '') {
            return false;
        }

        return ! in_array(mb_strtolower($said), [
            'n/a', 'na', 'n.a.', 'none', 'no', 'nil', '-', '--', 'x',
            'wala', 'walang sticker',
        ], true);
    }

    private function syncStickerStep(): void
    {
        // Only the sticker STATION step tracks needs_sticker now — the export is a
        // the tech pack's file location, so nothing artist-side here.
        foreach ([['Sticker', User::JOB_SUPPLY_CHAIN]] as [$dept, $team]) {
            $existing = $this->tasks()->where('department', $dept)->first();

            if ($this->needs_sticker && ! $existing) {
                $seq = (int) $this->tasks()->max('sequence');
                $add = $this->taskAdder($seq);
                $add(3, $dept, $team);
            } elseif (! $this->needs_sticker && $existing && $existing->status === 'todo') {
                $existing->delete();
            }
        }
    }

    /**
     * Embroidery runs on the sewn garment — one step alongside sewing on the
     * sample (stage 7) and the batch (stage 13). The routing rebuild only touches
     * 4/5/11, so keep the embroidery steps in step here (never removing worked ones).
     */
    private function syncEmbroideryStep(): void
    {
        $needs = (bool) $this->jobOrder?->needs_embroidery;
        $label = self::DECORATION_METHODS['embroidery'];

        // The embroidery steps run on the sewn garment (stages 7 & 13) and appear
        // only when embroidery is set. There is no artist export step — the
        // tech pack's file location covers the embroidery file too.
        //
        // Stage 7 is the SAMPLE sewing line. A job that skips the sample has
        // no stage 7 at all - the step builder leaves the whole phase out for
        // exactly that reason - so adding one here put a lone Embroidery step
        // in a stage nothing else occupies, on a job that runs 3 -> 10. The
        // batch's embroidery, beside stage 13's Sewing, is the real one.
        $stages = $this->skip_sample ? [13] : [7, 13];

        foreach ([7, 13] as $stage) {
            $existing = $this->tasks()->where('stage', $stage)->where('department', $label)->first();
            $wanted = $needs && in_array($stage, $stages, true);

            if ($wanted && ! $existing) {
                $seq = (int) $this->tasks()->max('sequence');
                $add = $this->taskAdder($seq);
                $add($stage, $label, User::JOB_PRODUCTION);
            } elseif (! $wanted && $existing && $existing->status === 'todo') {
                // Only an untouched one. Work somebody has started is never
                // taken off them by a rebuild.
                $existing->delete();
            }
        }
    }

    /** Renumber every task so the pipeline reads in stage order again. */
    private function resequenceTasks(): void
    {
        $tasks = Task::where('production_order_id', $this->id)
            ->orderBy('stage')->orderBy('id')->get();

        // (production_order_id, sequence) is unique, so renumbering in place
        // collides with a number still held by another row. Park them clear of
        // the range in use first, then write the final order. (sequence is a
        // TINYINT — max 255 — so the offset has to stay small.)
        foreach ($tasks as $i => $task) {
            $task->update(['sequence' => 100 + $i]);
        }

        foreach ($tasks as $i => $task) {
            $task->update(['sequence' => $i + 1]);
        }
    }

    /**
     * Open the earliest stage that still has TODO work once everything before it
     * is finished — used after a rebuild so nothing sits locked.
     */
    private function releaseNextReadyStage(): void
    {
        $stages = Task::where('production_order_id', $this->id)
            ->distinct()->orderBy('stage')->pluck('stage');

        foreach ($stages as $stage) {
            $earlierUnfinished = Task::where('production_order_id', $this->id)
                ->where('stage', '<', $stage)
                ->whereNotIn('status', ['complete', 'cancelled'])
                ->exists();

            if ($earlierUnfinished) {
                return;
            }

            if (Task::where('production_order_id', $this->id)
                ->where('stage', $stage)->where('status', 'todo')->exists()) {
                $this->unlockStage($stage);

                return;
            }
        }
    }

    /**
     * The job already written from this brief, if any.
     *
     * One inquiry, one job order number. A brief can carry several designs and
     * each of them used to be written up with a number of its own, which is
     * how one brief's 830 pieces ended up under two numbers that nothing on
     * either sheet said belonged together.
     *
     * Keyed on the BRIEF rather than the client on purpose. A client is not a
     * job: the same person can have two unrelated enquiries running at once,
     * and folding the second into the first's number would say they were one
     * piece of work. What makes several orders one job is that they came from
     * one brief.
     *
     * Only while the work is live. A brief whose job is delivered or cancelled
     * is finished with; anything written from it afterwards starts again.
     */
    public static function openJobFor(?int $inquiryId): ?self
    {
        if (! $inquiryId) {
            return null;
        }

        return self::where('inquiry_id', $inquiryId)
            ->whereIn('status', ['active', 'on_hold'])
            ->orderBy('id')
            ->first();
    }

    /** A suggested job order number (the officer can type their own). */
    public static function nextOrderNumber(): string
    {
        $year = now()->format('Y');

        // The highest number used this year, not how many there are.
        //
        // Counting breaks the moment one is cancelled and removed: three orders
        // less one leaves a count of two, which offers 00003 — a number already
        // on a job — and the save fails on the unique index with nothing the
        // officer can do about it, now that the box is read-only.
        //
        // Worked out here rather than in SQL: MAX(CAST(SUBSTRING(...))) is
        // MySQL's spelling, and the tests run on SQLite. A year of numbers is
        // a short list.
        $highest = self::where('order_number', 'like', "IC{$year}-%")
            ->pluck('order_number')
            ->map(fn ($number) => preg_match('/^IC'.$year.'-(\d+)$/', (string) $number, $m) ? (int) $m[1] : 0)
            ->max();

        return sprintf('IC%s-%05d', $year, ((int) $highest) + 1);
    }

    /* ==================== Payments & stage engine ==================== */

    /**
     * Record a payment. The downpayment lets the account officer fill the job
     * order; the design sample itself is only released once that job order is
     * SENT to the artist (the artist needs the JO to know what to make).
     */
    public function recordPayment(array $data): Payment
    {
        $wasFirst = ! $this->hasDownpayment();

        return $this->payments()->create([
            'amount' => $data['amount'],
            'method' => $data['method'] ?? null,
            'reference' => $data['reference'] ?? null,
            'proof_path' => $data['proof_path'] ?? null,
            'proof_name' => $data['proof_name'] ?? null,
            'kind' => $data['kind'] ?? ($wasFirst ? 'downpayment' : 'payment'),
            'note' => $data['note'] ?? null,
            'paid_at' => $data['paid_at'] ?? now(),
            'recorded_by' => $data['recorded_by'] ?? null,
        ]);
    }

    /**
     * The order's pieces per size, largest run first in the chart's own order.
     *
     * Sizes with nothing on them are left out — there is no point raising a
     * request for a size the order does not include.
     *
     * @return array<string, int> size => pieces
     */
    public function sizeQuantities(): array
    {
        return $this->itemsInSizeOrder()
            ->filter(fn ($i) => filled($i->size) && (int) $i->quantity > 0)
            ->mapWithKeys(fn ($i) => [(string) $i->size => (int) $i->quantity])
            ->all();
    }

    /**
     * Divide one material's amount between the sizes that need it.
     *
     * The job order says how much of a material the whole run takes, not how
     * much each size takes — nobody is going to type that twelve times. So the
     * amount follows the pieces: a size that is a third of the run carries a
     * third of the fabric.
     *
     * The last size absorbs the rounding, so the parts still add up to exactly
     * what was asked for rather than drifting a few centavos short.
     *
     * When nobody said how much, every size inherits that silence — the desk
     * types the amount, as it did before.
     *
     * @param  array<string, int>  $sizes
     * @return array<string, float|null> size => amount
     */
    public static function splitAcrossSizes(?float $needs, array $sizes): array
    {
        if ($sizes === []) {
            return ['' => $needs];
        }

        if ($needs === null) {
            return array_map(fn () => null, $sizes);
        }

        $pieces = array_sum($sizes);

        if ($pieces <= 0) {
            return ['' => $needs];
        }

        $split = [];
        $given = 0.0;
        $last = array_key_last($sizes);

        foreach ($sizes as $size => $qty) {
            $share = $size === $last
                ? round($needs - $given, 2)
                : round($needs * $qty / $pieces, 2);

            $split[$size] = $share;
            $given += $share;
        }

        return $split;
    }

    /**
     * Turn the job order's raw-materials list into stock requests for the
     * raw-materials desk to issue or reject.
     *
     * Called both when the job order is sent AND whenever the materials are
     * saved on the production-details page — the list is usually filled in
     * after sending, so creating them only on send meant they never appeared.
     *
     * @return int how many requests were newly created
     */
    public function syncMaterialRequests(): int
    {
        $materials = $this->jobOrder?->rawMaterialsList() ?? [];
        $materials = array_values(array_filter($materials, fn ($m) => filled($m)));

        // One line per size the order actually asks for. An order with no size
        // breakdown keeps the single unsized line it always raised.
        $sizes = $this->sizeQuantities();

        $new = 0;
        $wanted = [];

        foreach ($materials as $material) {
            $needs = $this->jobOrder?->rawMaterialQuantity($material);

            foreach (self::splitAcrossSizes($needs, $sizes) as $size => $share) {
                $wanted[] = $material.'|'.$size;

                $mr = MaterialRequest::firstOrCreate(
                    ['production_order_id' => $this->id, 'material' => $material, 'size' => $size],
                    ['status' => 'pending', 'requested_quantity' => $share]
                );

                if ($mr->wasRecentlyCreated) {
                    $new++;
                } elseif ($mr->status === 'pending' && (float) $mr->requested_quantity !== (float) $share) {
                    // The job order was corrected before the desk got to it.
                    $mr->update(['requested_quantity' => $share]);
                }
            }
        }

        // Drop still-pending requests for material/size pairs that are no longer
        // asked for — a material struck off the list, or a size dropped from the
        // order. Anything already issued or rejected is history and stays.
        //
        // Matched in PHP rather than SQL because the pair is what identifies a
        // request now, and there is no portable way to say "not in this set of
        // pairs" across MySQL and SQLite.
        $this->materialRequests()
            ->where('status', 'pending')
            ->get()
            ->reject(fn ($mr) => in_array($mr->material.'|'.$mr->size, $wanted, true))
            ->each(fn ($mr) => $mr->delete());

        if ($new > 0) {
            AppNotification::toRole(
                User::JOB_SUPPLY_CHAIN,
                '📦 New material request',
                "{$this->order_number} — {$new} material request(s) to fulfil.",
                route('inventory.requests'),
            );
        }

        return $new;
    }

    /**
     * Set every TODO task in a stage to READY, auto-assigning it to whoever on
     * its team is present today. Review-only steps (the job order) go straight
     * to the approver with no agent work.
     */
    public function unlockStage(int $stage): void
    {
        // A partial design approval can release the sample / pre-production
        // work, but the full batch must never start while any design is still
        // waiting on the client. Stage 10 is the mass-production stage.
        //
        // Asked as "is anything still outstanding?" rather than "is the layout
        // approved?". The second question has no yes for an order whose
        // enquiry never had a layout at all - a walk-in with their own artwork
        // - so those orders reached the batch and silently stopped there, with
        // nothing on any screen to say why.
        if ($stage === 10) {
            $inquiry = \App\Models\Inquiry::where('production_order_id', $this->id)->first();

            if ($inquiry && $inquiry->designsOutstanding()->isNotEmpty()) {
                return;
            }
        }

        // When the Raw materials step opens — which is the moment the leader
        // approves the design package — raise a stock request for each material
        // on the job order.
        if ($this->tasks()->where('stage', $stage)->where('department', 'Raw materials')->exists()) {
            $this->syncMaterialRequests();
        }

        // When the Inventory step opens, queue the finished products so the
        // inventory desk can count what actually arrived.
        if ($this->tasks()->where('stage', $stage)->where('department', 'Inventory')->exists()) {
            $this->queueProductReceipts();
        }

        $todo = $this->tasks()->where('stage', $stage)->where('status', 'todo')->get();
        $stageAll = $this->tasks()->where('stage', $stage)->get();

        foreach ($todo as $task) {
            // Hold a step whose same-stage prerequisite(s) aren't finished yet
            // (the press waits for Printer AND Raw materials). Released in
            // handleTaskCompleted once they complete.
            if (! self::prerequisitesMet($task->department, $stageAll)) {
                continue;
            }

            // The Tech Pack is the officer's sheet handed over, not merely the
            // next step after the mockup: the artist works FROM it, and opening
            // it is refused until it has been sent. handleTaskCompleted has
            // held it back for that reason for a while; this path did not, so
            // anything that unlocked the whole stage at once - a waived
            // downpayment, a confirmed payment - released a step whose page
            // then answered "not open yet". The artist was given work they
            // could not start, and the board said it was theirs.
            //
            // sendToArtist() unlocks this stage again once the sheet is on its
            // way, which is where the pack is meant to be released.
            if ($task->isTechPackStep() && $this->jobOrder?->status !== 'sent_to_artist') {
                continue;
            }

            if ($task->auto_submit) {
                // Nothing to "do" — it lands on the approver's desk immediately.
                $task->status = 'for_checking';
                $task->submitted_at = now();
                $task->released_at ??= now();
                $task->save();

                continue;
            }

            $task->status = 'ready';
            $task->released_at ??= now();

            if ($task->auto_assign && ! $task->assigned_to) {
                // Keep the same artist across the design → template steps: reuse
                // whoever already worked an artist step on this order if they're
                // still active; otherwise fall back to round-robin.
                $same = $task->team === User::JOB_ARTIST
                    ? $this->tasks()
                        ->where('team', User::JOB_ARTIST)
                        ->whereNotNull('assigned_to')
                        ->orderByDesc('sequence')
                        ->first()?->assignee
                    : null;

                if ($same && $same->is_active) {
                    $task->assigned_to = $same->id;
                } else {
                    $staff = StaffAssigner::next($task->team);
                    if ($staff) {
                        $task->assigned_to = $staff->id;
                    }
                }
            }

            $task->save();

            // Desktop alert to whoever just received the released task.
            $task->notifyAssignee();
        }
    }

    /** Called when a task reaches COMPLETE: unlock the next stage if this one is done. */
    public function handleTaskCompleted(Task $task): void
    {
        $stageTasks = $this->tasks()->where('stage', $task->stage)->get();

        // Release any held step in this stage whose same-stage prerequisite is now
        // complete (the press once Printer and Raw materials are done;
        // Embroidery once Sewing). Each held step runs on its own, after its prereq.
        $releasedAny = false;
        foreach ($stageTasks->where('status', 'todo') as $held) {
            // Only steps that actually have prerequisites, and only once ALL of
            // them are done (the press needs Printer AND Raw materials).
            if (! isset(self::STEP_PREREQUISITES[$held->department])) {
                continue;
            }
            // After mockup approval, the account officer completes and sends
            // the Tech Pack. Do not release that artist task merely because its
            // mockup prerequisite finished.
            if ($held->isTechPackStep() && $this->jobOrder?->status !== 'sent_to_artist') {
                continue;
            }
            if (self::prerequisitesMet($held->department, $stageTasks)) {
                $held->status = 'ready';
                $held->released_at ??= now();
                if ($held->auto_assign && ! $held->assigned_to) {
                    $staff = StaffAssigner::next($held->team);
                    if ($staff) {
                        $held->assigned_to = $staff->id;
                    }
                }
                $held->save();
                $held->notifyAssignee();
                $releasedAny = true;
            }
        }
        if ($releasedAny) {
            $this->refreshCompletion();

            return;
        }

        if ($stageTasks->every(fn ($t) => $t->status === 'complete')) {
            // The layout is approved early, before payment. Pause here until
            // the money is settled — and no longer than that.
            //
            // It used to wait for the officer to SEND the job order as well,
            // which held the artist's own next piece of work behind somebody
            // else's paperwork: the mockup is drawn from the approved layout,
            // not from the tech pack, so there was nothing in the wait for it.
            // The artist starts the mockup while the officer fills their half.
            //
            // hasDownpayment() is confirmed money, or a job that owes nothing
            // at all — a sponsored sample has no payment coming to release it.
            if ($task->stage === self::STAGE_LAYOUT && ! $this->hasDownpayment()) {
                $this->refreshCompletion();

                return;
            }

            $next = $this->nextStageWithTasks($task->stage);
            if ($next !== null) {
                $this->unlockStage($next);
            }
        }

        $this->refreshCompletion();
    }

    public function nextStageWithTasks(int $afterStage): ?int
    {
        $stage = $this->tasks()->where('stage', '>', $afterStage)->min('stage');

        return $stage !== null ? (int) $stage : null;
    }

    public function refreshCompletion(): void
    {
        $tasks = $this->tasks()->get();

        if ($tasks->isNotEmpty() && $tasks->every(fn ($t) => $t->status === 'complete')) {
            // Receipts are queued earlier, when the Inventory stage opens — by the
            // time the order completes the inventory desk has already counted them.
            if ($this->status !== 'complete') {
                $this->update(['status' => 'complete', 'completed_at' => now()]);
            }
        }
    }

    /**
     * When an order finishes, queue one pending receipt per finished product
     * (or one for the whole order when it has no line items). The products desk
     * then confirms how many were actually received, which is what gets added
     * to stock. Idempotent per order; never throws, so it can't break order
     * completion.
     */
    public function queueProductReceipts(): void
    {
        try {
            if (\App\Models\ProductReceipt::where('production_order_id', $this->id)->exists()) {
                return;
            }

            $lines = $this->items;

            if ($lines->isEmpty()) {
                $lines = collect([(object) [
                    // The readable label, not the stored key — otherwise the
                    // stock line reads "round_neck" instead of "Round Neck".
                    'description' => $this->productLabel() ?: $this->customer_name,
                    'size' => null,
                    'quantity' => $this->quantity,
                ]]);
            }

            // Merge by PRODUCT (sizes combine into one line) so there's one receipt
            // per product per job order — only a job order with genuinely different
            // products gets more than one line.
            $byProduct = [];
            foreach ($lines as $line) {
                $qty = (float) ($line->quantity ?? 0);
                if ($qty <= 0) {
                    continue;
                }
                $product = trim((string) ($line->description ?? '')) ?: ($this->productLabel() ?: 'Products');
                $byProduct[$product] = ($byProduct[$product] ?? 0) + $qty;
            }

            foreach ($byProduct as $product => $qty) {
                // Prefix the order number so each job order's products stay their
                // own stock line (released together, per job order).
                \App\Models\ProductReceipt::create([
                    'production_order_id' => $this->id,
                    'name' => $this->order_number.' — '.$product,
                    'unit' => 'pcs',
                    'expected_quantity' => $qty,
                    'status' => 'pending',
                ]);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error(
                'queueProductReceipts failed for order '.$this->id.': '.$e->getMessage()
            );
        }
    }

    /**
     * The client approved the first physical sample — queue that one piece for
     * the inventory desk to receive.
     *
     * It used to be counted straight into finished-products stock on approval,
     * which put a garment on the shelf that nobody had handed over: stock said
     * one piece was there and had a Release button beside it, while the piece
     * itself was still in somebody's hands on the floor. Nothing enters stock
     * without being received now — the sample included.
     *
     * Idempotent per order (a second approval won't queue a second piece); never
     * throws, so it can't break sample approval.
     */
    public function stockFirstSample(): void
    {
        try {
            if (\App\Models\ProductReceipt::where('production_order_id', $this->id)
                ->where('is_sample', true)->exists()) {
                return;
            }

            $line = $this->items->first();

            if ($line) {
                $name = trim((string) ($line->description ?? '')) ?: ('Order '.$this->order_number);

                if (! empty($line->size)) {
                    $name .= ' ('.$line->size.')';
                }
            } else {
                $name = trim((string) ($this->product_type ?: $this->customer_name)) ?: ('Order '.$this->order_number);
            }

            \App\Models\ProductReceipt::create([
                'production_order_id' => $this->id,
                'name' => $name,
                'unit' => 'pcs',
                'expected_quantity' => 1,
                'status' => 'pending',
                'is_sample' => true,
            ]);

            AppNotification::toRole(
                User::JOB_PRODUCTION,
                '📦 Approved sample to receive',
                "{$this->order_number} — the client approved the sample. Receive the piece into finished goods.",
                route('products.index'),
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error(
                'stockFirstSample failed for order '.$this->id.': '.$e->getMessage()
            );
        }
    }

    /* ==================== Order status ==================== */

    public function hold(): void
    {
        if ($this->status === 'active') {
            $this->update(['status' => 'on_hold']);
        }
    }

    public function resume(): void
    {
        if ($this->status === 'on_hold') {
            $this->update(['status' => 'active']);
        }
    }

    public function cancel(): void
    {
        if (in_array($this->status, ['active', 'on_hold'], true)) {
            $this->update(['status' => 'cancelled']);
            $this->tasks()->whereNotIn('status', ['complete'])->update(['status' => 'cancelled']);
        }
    }
}
