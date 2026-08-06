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

    public const DECORATION_METHODS = [
        'embroidery' => 'Embroidery',
        'cap_press' => 'Cap press',
        'heat_press' => 'Heat press',
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

    /** Maximum pieces that may be due on any single date. */
    public const DAILY_CAPACITY = 500;

    /** VAT added to the total when the order is marked VAT inclusive. */
    public const VAT_RATE = 0.12;

    protected $fillable = [
        'order_number', 'brief_token', 'brief_expires_at', 'client_id', 'customer_name', 'product_type', 'description',
        'decoration_methods', 'cutting_type', 'needs_sticker',
        'massprod_priority', 'skip_sample', 'back_pocket', 'back_pocket_qty',
        'rush', 'rush_fee',
        'unit_price', 'total_price', 'vat_inclusive', 'discount_amount', 'discount_note',
        'quantity', 'due_date', 'status', 'completed_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
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
            'total_price' => 'decimal:2',
            'vat_inclusive' => 'boolean',
            'discount_amount' => 'decimal:2',
            'brief_expires_at' => 'datetime',
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

    public function pricingBreakdown(): array
    {
        $garment = $this->unit_price !== null ? (float) $this->unit_price * (int) $this->quantity : null;

        if ($garment === null) {
            return ['subtotal' => null, 'back_pocket' => 0.0, 'back_pocket_qty' => 0, 'addon' => 0.0, 'addon_label' => null, 'rush' => 0.0, 'discount' => 0.0, 'vatable' => null, 'vat' => 0.0, 'total' => null];
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
     * priced changes AFTER intake — today that's the Step 4 add-on.
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
    public static function bookedQtyForDate(string $date, ?int $exceptOrderId = null): int
    {
        return (int) self::whereDate('due_date', $date)
            ->where('status', '!=', 'cancelled')
            ->when($exceptOrderId, fn ($q) => $q->where('id', '!=', $exceptOrderId))
            ->sum('quantity');
    }

    public function productLabel(): ?string
    {
        // Known priced product → its config label; otherwise a custom apparel
        // type (e.g. Rash Guard) stored as free text — show it as-is.
        return \App\Services\PricingService::label($this->product_type)
            ?? ($this->product_type ? \Illuminate\Support\Str::title($this->product_type) : null);
    }

    /** Outstanding balance = total price minus everything paid so far. */
    public function balance(): ?float
    {
        if ($this->total_price === null) {
            return null;
        }

        return max(0, (float) $this->total_price - (float) $this->payments()->sum('amount'));
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->orderBy('sequence');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
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

    /** The layout stage has been released to an artist (no longer just TODO). */
    public function layoutReleased(): bool
    {
        return $this->tasks()
            ->where('stage', self::STAGE_LAYOUT)
            ->where('status', '!=', 'todo')
            ->exists();
    }

    /** Every task in the layout stage is complete (client approved the layout). */
    public function layoutApproved(): bool
    {
        $layout = $this->tasks()->where('stage', self::STAGE_LAYOUT)->get();

        return $layout->isNotEmpty() && $layout->every(fn ($t) => $t->status === 'complete');
    }

    public function hasDownpayment(): bool
    {
        return $this->payments()->exists();
    }

    public function totalPaid(): string
    {
        return number_format((float) $this->payments()->sum('amount'), 2);
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
        'Cap press' => 'Cap Press',
        'Heat press' => 'Heat Press',
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
     * them. Both the printer AND the sticker station wait for the single Export
     * step; embroidery is done after the garment is sewn.
     */
    public const STEP_PREREQUISITES = [
        'Printer' => 'Export',
        'Sticker' => 'Export',
        'Embroidery' => 'Sewing',
        // The press can't run until the print is ready (Printer) AND the fabric
        // has been issued (Raw materials) — you press the transfer onto the cloth.
        'Cap press' => ['Printer', 'Raw materials'],
        'Heat press' => ['Printer', 'Raw materials'],
        'Small press' => ['Printer', 'Raw materials'],
        'Roller press' => ['Printer', 'Raw materials'],
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

    /** Resolve a department to its specific role, else the broad fallback team. */
    public static function teamFor(string $department, ?string $fallback, array $activeRoles): ?string
    {
        $role = self::DEPARTMENT_ROLES[$department] ?? null;

        return ($role !== null && in_array($role, $activeRoles, true)) ? $role : $fallback;
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

        // 2 — Final mockup AND production template, approved by leader.
        $add(2, 'Final mockup', $artist, 'leader');
        $add(2, 'Production template', $artist, 'leader');

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

        // Artist export steps (stage 3): the print-ready files production needs.
        // These come FIRST in the production stage — right after the template —
        // because the artist exports straight after finishing the template, and
        // the printer/sticker stay locked until the matching file is uploaded.
        // A single Export step covers ALL the print-ready files (print, sticker
        // and embroidery). There are no separate "Export sticker"/"Export
        // embroidery" steps — the printer and sticker station both wait on Export.
        $artist = User::JOB_ARTIST;
        $add(3, 'Export', $artist);

        // 3 — supply: materials, printing, and the free logo sticker if ordered.
        $add(3, 'Raw materials', $supply);
        $add(3, 'Printer', $supply);

        if ($this->needs_sticker) {
            $add(3, 'Sticker', $supply);
        }

        // Decoration press runs at stage 3, gated on the Printer (see
        // STEP_PREREQUISITES) so it starts as soon as printing is done — not
        // waiting on raw materials or the sticker. Embroidery is NOT here — it's
        // done on the sewn garment, after sewing (see below).
        foreach ($this->decorationSteps($decorationMethods) as $label) {
            if ($label === self::DECORATION_METHODS['embroidery']) {
                continue;
            }
            $add(3, $label, $prod);
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

        // 16 — the account officer confirms the client actually received it.
        // Nobody "works" this step, so it lands on their desk the moment stock
        // is counted in (auto_submit) and the order closes when they confirm.
        $add(16, 'Release to client', null, 'sales', true);
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
            // The presses live at stage 3 (gated on the printer); drop the old
            // press steps (only if not started) so the new choices replace them.
            $this->tasks()->where('stage', 3)->whereIn('department', $presses)->where('status', 'todo')->delete();

            $seq = (int) $this->tasks()->max('sequence');
            $add = $this->taskAdder($seq);
            $prod = User::JOB_PRODUCTION;

            foreach ($this->decorationSteps($decorationMethods) as $label) {
                if ($label === self::DECORATION_METHODS['embroidery']) {
                    continue;   // embroidery lives with sewing (stages 7/13), synced below
                }
                $add(3, $label, $prod);   // decoration press at stage 3, gated on the printer
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
    private function syncStickerStep(): void
    {
        // Only the sticker STATION step tracks needs_sticker now — the export is a
        // single step (no separate "Export sticker"), so nothing artist-side here.
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
        // only when embroidery is set. There is no separate artist "Export
        // embroidery" step — the single Export step covers the embroidery file.
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
        $countThisYear = self::where('order_number', 'like', "IC{$year}-%")->count();

        return sprintf('IC%s-%05d', $year, $countThisYear + 1);
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
            $mr = MaterialRequest::firstOrCreate(
                ['production_order_id' => $this->id, 'material' => $material],
                ['status' => 'pending']
            );

            if ($mr->wasRecentlyCreated) {
                $new++;
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
            // (Printer waits for Export; the press waits for Printer AND Raw
            // materials). Released in handleTaskCompleted once they complete.
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
        // complete (Printer and Sticker once Export is done;
        // Embroidery once Sewing). Each held step runs on its own, after its prereq.
        $releasedAny = false;
        foreach ($stageTasks->where('status', 'todo') as $held) {
            // Only steps that actually have prerequisites, and only once ALL of
            // them are done (the press needs Printer AND Raw materials).
            if (! isset(self::STEP_PREREQUISITES[$held->department])) {
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
            // The layout is approved early, before payment. Pause here until the
            // account officer collects the downpayment and SENDS the job order —
            // that's what releases the final mockup (stage 2). See
            // ProductionOrderController::sendJobOrderToArtist().
            if ($task->stage === self::STAGE_LAYOUT && $this->jobOrder?->status !== 'sent_to_artist') {
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
                    'description' => $this->product_type ?: $this->customer_name,
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
                $product = trim((string) ($line->description ?? '')) ?: ($this->product_type ?: 'Products');
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
     * The client approved the first physical sample — count that one piece into
     * finished-products stock straight away, so the approved sample is the first
     * unit in inventory. Idempotent per order (a second approval won't add a
     * second piece); never throws, so it can't break sample approval.
     */
    public function stockFirstSample(): void
    {
        try {
            // Already stocked once for this order? Do nothing.
            if (\App\Models\ProductMovement::where('production_order_id', $this->id)
                ->where('reason', 'sample')->exists()) {
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

            $product = \App\Models\ProductItem::firstOrCreate(
                ['name' => $name],
                ['unit' => 'pcs', 'quantity' => 0]
            );

            $product->recordMovement(
                1,
                'sample',
                'Approved first sample for order '.$this->order_number,
                $this->id,
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
