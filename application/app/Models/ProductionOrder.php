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

    /**
     * A due date this close is a rush job.
     *
     * Ten days is roughly what the line needs to go from layout to a garment
     * in a box without anybody skipping a step or working a Sunday. Shorter
     * than that is sometimes the right call — but it should be a decision
     * somebody makes on purpose, not something noticed later on the calendar.
     */
    public const RUSH_NOTICE_DAYS = 10;

    protected $fillable = [
        'order_number', 'brief_token', 'brief_expires_at', 'client_id', 'customer_name', 'product_type', 'price_list', 'description',
        'decoration_methods', 'cutting_type', 'needs_sticker',
        'massprod_priority', 'skip_sample', 'back_pocket', 'back_pocket_qty',
        'rush', 'rush_fee',
        'unit_price', 'custom_size_price', 'total_price', 'vat_inclusive', 'discount_amount', 'discount_note',
        'quantity', 'due_date', 'layout_approved_at', 'status', 'completed_at', 'created_by',
        'mockup_offset_x', 'mockup_offset_y',
        'replaces_order_id', 'replacement_reason',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'layout_approved_at' => 'datetime',
            'completed_at' => 'datetime',
            'decoration_methods' => 'array',
            'back_pocket' => 'boolean',
            'back_pocket_qty' => 'integer',
            'massprod_priority' => 'boolean',
            'skip_sample' => 'boolean',
            'rush' => 'boolean',
            'rush_fee' => 'decimal:2',
            'needs_sticker' => 'boolean',
            'unit_price' => 'decimal:2',
            'custom_size_price' => 'decimal:2',
            'total_price' => 'decimal:2',
            'vat_inclusive' => 'boolean',
            'discount_amount' => 'decimal:2',
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
     * subtotal → less discount → plus 12% VAT (when ticked) → total.
     *
     * @return array{subtotal: ?float, discount: float, vatable: ?float, vat: float, total: ?float}
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
            return ['subtotal' => null, 'charted_qty' => 0, 'custom_size_qty' => 0, 'custom_size_amount' => 0.0, 'back_pocket' => 0.0, 'back_pocket_qty' => 0, 'addon' => 0.0, 'addon_label' => null, 'rush' => 0.0, 'discount' => 0.0, 'vatable' => null, 'vat' => 0.0, 'total' => null];
        }

        $backPocket = $this->backPocketAmount();
        $addon = $this->addonAmount();
        $rush = $this->rushAmount();
        $gross = $garment + $backPocket + $addon + $rush;   // before discount
        $discount = min((float) $this->discount_amount, $gross);
        $vatable = round($gross - $discount, 2);
        $vat = $this->vat_inclusive ? round($vatable * self::VAT_RATE, 2) : 0.0;

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
            'discount' => round($discount, 2),
            'vatable' => $vatable,
            'vat' => $vat,
            'total' => round($vatable + $vat, 2),
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
    public static function computeTotal(?float $unitPrice, int $qty, float $discount = 0, bool $vat = false, float $backPocketAmount = 0, float $extras = 0): ?float
    {
        if ($unitPrice === null) {
            return null;
        }

        // $extras covers one-off charges on the job rather than per piece —
        // the rush fee, and the Step 4 add-on.
        $vatable = max(0, ($unitPrice * $qty) + $backPocketAmount + $extras - $discount);

        return round($vat ? $vatable * (1 + self::VAT_RATE) : $vatable, 2);
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
    public function techPack(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(TechPack::class);
    }

    public function techPackOrNew(): TechPack
    {
        return $this->techPack ?? $this->techPack()->make();
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

    public function hasDownpayment(): bool
    {
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
     * The order has a due date and the floor has sixteen steps to reach it, so
     * "due the 14th" told a sewer nothing about whether they were late. The
     * span from the confirmed downpayment to the due date is shared out evenly
     * and each step gets its own moment, in sequence — step one a share in,
     * the last one landing on the due date itself.
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
    public function scheduleStepDeadlines(?\Carbon\CarbonInterface $from = null): int
    {
        if (! $this->due_date) {
            return 0;
        }

        $steps = $this->tasks()->orderBy('sequence')->get();

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

        $minutes = $start->diffInMinutes($end);
        $each = $minutes / $steps->count();

        $last = $steps->count() - 1;

        foreach ($steps->values() as $i => $step) {
            // The last step IS the due date, not a sum that rounds towards it —
            // half a minute of rounding was landing it the day after.
            $step->update([
                'due_at' => $i === $last
                    ? $end
                    : $start->copy()->addMinutes((int) round($each * ($i + 1))),
            ]);
        }

        return $steps->count();
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

            return $order;
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

        // 10 — mass production (prints the whole batch; the entire order when the
        // sample was skipped).
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
        $steps = [[7, $label, User::JOB_PRODUCTION], [13, $label, User::JOB_PRODUCTION]];

        foreach ($steps as [$stage, $dept, $team]) {
            $existing = $this->tasks()->where('stage', $stage)->where('department', $dept)->first();

            if ($needs && ! $existing) {
                $seq = (int) $this->tasks()->max('sequence');
                $add = $this->taskAdder($seq);
                $add($stage, $dept, $team);
            } elseif (! $needs && $existing && $existing->status === 'todo') {
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

        $new = 0;
        foreach ($materials as $material) {
            $needs = $this->jobOrder?->rawMaterialQuantity($material);

            $mr = MaterialRequest::firstOrCreate(
                ['production_order_id' => $this->id, 'material' => $material],
                ['status' => 'pending', 'requested_quantity' => $needs]
            );

            if ($mr->wasRecentlyCreated) {
                $new++;
            } elseif ($mr->status === 'pending' && (float) $mr->requested_quantity !== (float) $needs) {
                // The job order was corrected before the desk got to it.
                $mr->update(['requested_quantity' => $needs]);
            }
        }

        // Drop still-pending requests for materials that were removed from the
        // list. Anything already issued or rejected is history and stays.
        $this->materialRequests()
            ->where('status', 'pending')
            ->when($materials !== [], fn ($q) => $q->whereNotIn('material', $materials))
            ->delete();

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
