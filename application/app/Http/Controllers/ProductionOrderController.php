<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOrderAccess;
use App\Models\Client;
use App\Models\JobOrder;
use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ProductionOrderController extends Controller
{
    use AuthorizesOrderAccess;

    /**
     * Every order this person is allowed to see, before searching or paging.
     * Account officers see only their own orders; leaders/admin see all.
     */
    private function visibleOrders(Request $request)
    {
        return ProductionOrder::query()
            ->when($request->user()->isSales(), fn ($q) => $q->where('created_by', $request->user()->id));
    }

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));

        // Only the four real statuses filter; anything else means "all".
        $status = in_array($request->query('status'), ProductionOrder::STATUSES, true)
            ? $request->query('status')
            : '';

        // Sort orders by workflow priority. FIELD() is MySQL-only, so fall back
        // to a portable CASE on other drivers (e.g. SQLite in tests).
        $statusOrder = DB::getDriverName() === 'mysql'
            ? "FIELD(status, 'active', 'on_hold', 'complete', 'cancelled')"
            : "CASE status WHEN 'active' THEN 1 WHEN 'on_hold' THEN 2 WHEN 'complete' THEN 3 WHEN 'cancelled' THEN 4 ELSE 5 END";

        // One page at a time. The list only ever grows, so loading it whole
        // would get slower every week the shop stays busy.
        $orders = $this->visibleOrders($request)
            ->with('tasks')
            // Answered per row on the list, so answer it in this query rather
            // than once per order (see hasDownpayment).
            ->withExists('payments')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('order_number', 'like', "%{$search}%")
                ->orWhere('customer_name', 'like', "%{$search}%")))
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->orderByRaw($statusOrder)
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // The summary cards count every order the person can see, not just the
        // page in front of them — a total that changed as you paged would be
        // useless for telling the office how much work is open.
        $counts = $this->visibleOrders($request)
            ->toBase()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('orders.index', [
            'orders' => $orders,
            'search' => $search,
            'status' => $status,
            'counts' => $counts,
            'totalOrders' => (int) $counts->sum(),
        ]);
    }


    public function create(): View
    {
        return view('orders.create', [
            // Listed surname-first so the office can find a client by family name.
            'clients' => Client::bySurname()->get(),
            'decorationMethods' => ProductionOrder::DECORATION_METHODS,
            'cuttingTypes' => ProductionOrder::CUTTING_TYPES,
            'products' => \App\Services\PricingService::products(),
            'backPocketFee' => \App\Services\PricingService::backPocketFee(),
            'nextNumber' => ProductionOrder::nextOrderNumber(),
        ]);
    }

    /**
     * The size breakdown: the fixed chart sizes plus an optional typed "Others"
     * size (e.g. "Kids 8"). Keyed by size => quantity.
     */
    private function collectSizes(array $data): \Illuminate\Support\Collection
    {
        $sizes = collect($data['sizes'] ?? [])
            ->only(ProductionOrder::SIZES)
            ->filter(fn ($q) => (int) $q > 0)
            ->map(fn ($q) => (int) $q);

        $otherLabel = trim((string) ($data['other_size'] ?? ''));
        $otherQty = (int) ($data['other_size_qty'] ?? 0);

        if ($otherLabel !== '' && $otherQty > 0) {
            $sizes->put($otherLabel, $otherQty);
        }

        return $sizes;
    }

    /**
     * Production can only handle DAILY_CAPACITY pieces per due date. Returns an
     * error message when this order would push that date over, else null.
     */
    private function capacityError(?string $dueDate, int $qty, ?int $exceptOrderId = null): ?string
    {
        if (blank($dueDate)) {
            return null;
        }

        $booked = ProductionOrder::bookedQtyForDate($dueDate, $exceptOrderId);
        $cap = ProductionOrder::DAILY_CAPACITY;

        if ($booked + $qty <= $cap) {
            return null;
        }

        $when = \Illuminate\Support\Carbon::parse($dueDate)->format('M j, Y');
        $left = max(0, $cap - $booked);

        return "{$when} already has ".number_format($booked)." of ".number_format($cap)
            .' pcs booked — only '.number_format($left).' left. Pick another due date.';
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            // Typed by the account officer (their own numbering, e.g. IC2026-00016).
            'order_number' => ['required', 'string', 'max:50', 'unique:production_orders,order_number'],

            // Existing client OR a new one typed in. For a NEW client every
            // detail except company and TIN is required, so half-filled
            // records don't reach the database — the officer is told exactly
            // which ones are missing instead of finding out later.
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'client_name' => ['required_without:client_id', 'nullable', 'string', 'max:255'],
            'client_last_name' => ['required_without:client_id', 'nullable', 'string', 'max:255'],
            'client_contact' => ['required_without:client_id', 'nullable', 'string', 'max:255'],
            'client_office_address' => ['required_without:client_id', 'nullable', 'string', 'max:255'],
            'client_delivery_address' => ['required_without:client_id', 'nullable', 'string', 'max:255'],
            // Genuinely optional.
            'client_company' => ['nullable', 'string', 'max:255'],
            'client_tin' => ['nullable', 'string', 'max:50'],

            'description' => ['nullable', 'string', 'max:1000'],
            'due_date' => ['required', 'date'],

            // How many of each size (the inquiry breakdown). Total = quantity.
            'sizes' => ['required', 'array'],
            'sizes.*' => ['nullable', 'integer', 'min:0', 'max:100000'],
            // A size that isn't on the chart (e.g. "Kids 8"), typed by the officer.
            'other_size' => ['nullable', 'string', 'max:50'],
            'other_size_qty' => ['nullable', 'integer', 'min:0', 'max:100000'],

            'vat_inclusive' => ['nullable', 'boolean'],
            'discount_amount' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'discount_note' => ['nullable', 'string', 'max:255'],

            'product_type' => ['required', 'string', 'in:'.implode(',', [...array_keys(\App\Services\PricingService::products()), '__other__'])],
            'product_type_custom' => ['nullable', 'required_if:product_type,__other__', 'string', 'max:100'],
            'back_pocket' => ['nullable', 'boolean'],
            'back_pocket_qty' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'massprod_priority' => ['nullable', 'boolean'],
            'skip_sample' => ['nullable', 'boolean'],
            'unit_price_override' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            // Rush: the fee is agreed per job, so it must be entered when ticked.
            'rush' => ['nullable', 'boolean'],
            'rush_fee' => ['nullable', 'required_if:rush,1', 'numeric', 'min:0', 'max:10000000'],
        ], [
            'client_last_name.required_without' => "Enter the client's last name.",
            'client_contact.required_without' => 'Enter the contact number.',
            'client_office_address.required_without' => 'Enter the office address.',
            'client_delivery_address.required_without' => 'Enter the delivery address.',
            'rush_fee.required_if' => 'Enter the rush fee, or untick Rush order.',
        ]);

        // A custom apparel type (e.g. Rash Guard) isn't in the price list, so it
        // goes to quotation — the agent types the price below.
        if ($data['product_type'] === '__other__') {
            $data['product_type'] = \Illuminate\Support\Str::title(trim($data['product_type_custom']));
        }

        // Decoration & cutting are chosen later, on the job order.
        $data['decoration_methods'] = [];
        $data['cutting_type'] = null;

        $sizes = $this->collectSizes($data);

        if ($sizes->isEmpty()) {
            return back()->withInput()->withErrors(['sizes' => 'Enter how many pieces for at least one size.']);
        }

        $data['quantity'] = $sizes->sum();

        // Production can only take so many pieces per day.
        if ($msg = $this->capacityError($data['due_date'], $data['quantity'])) {
            return back()->withInput()->withErrors(['due_date' => $msg]);
        }

        // Price per piece: standard tier price (+ back pocket), unless the sales
        // agent overrides it (specials, or over-100 quotations).
        $backPocket = (bool) ($data['back_pocket'] ?? false);
        $backPocketQty = $backPocket ? (int) ($data['back_pocket_qty'] ?? $data['quantity']) : null;
        $quote = \App\Services\PricingService::quote($data['product_type'], $data['quantity'], $backPocket, $backPocketQty);
        // The service normalises/caps the pocket count and its charge.
        $backPocketQty = $backPocket ? $quote['back_pocket_qty'] : null;
        $backPocketAmount = $quote['back_pocket_amount'];

        if (! empty($data['unit_price_override'])) {
            $unitPrice = (float) $data['unit_price_override'];
        } else {
            $unitPrice = $quote['unit']; // null when a quotation is needed (>100)
        }

        // A rush order carries a one-off fee agreed for that job.
        $rush = (bool) ($data['rush'] ?? false);
        $rushFee = $rush ? round((float) ($data['rush_fee'] ?? 0), 2) : null;

        // Total = (unit x qty) + back pocket + rush, less the discount, then +12% VAT when ticked.
        $vat = (bool) ($data['vat_inclusive'] ?? false);
        $discount = (float) ($data['discount_amount'] ?? 0);
        $totalPrice = ProductionOrder::computeTotal(
            $unitPrice, $data['quantity'], $discount, $vat, $backPocketAmount, (float) $rushFee
        );

        $clientFields = [
            'contact_number' => $data['client_contact'] ?? null,
            'company' => $data['client_company'] ?? null,
            'office_address' => $data['client_office_address'] ?? null,
            'delivery_address' => $data['client_delivery_address'] ?? null,
            'tin' => $data['client_tin'] ?? null,
        ];

        $client = ! empty($data['client_id'])
            ? Client::findOrFail($data['client_id'])
            : Client::create($clientFields + [
                'name' => $data['client_name'],
                'last_name' => $data['client_last_name'] ?? null,
                'created_by' => $request->user()->id,
            ]);

        $order = ProductionOrder::createJobOrder([
            'order_number' => $data['order_number'],
            'client_id' => $client->id,
            'customer_name' => $client->fullName(),
            'product_type' => $data['product_type'],
            'description' => $data['description'] ?? null,
            'quantity' => $data['quantity'],
            'due_date' => $data['due_date'],
            'back_pocket' => $backPocket,
            'back_pocket_qty' => $backPocketQty,
            'massprod_priority' => (bool) ($data['massprod_priority'] ?? false),
            'skip_sample' => (bool) ($data['skip_sample'] ?? false),
            'rush' => $rush,
            'rush_fee' => $rushFee,
            'unit_price' => $unitPrice,
            'total_price' => $totalPrice,
            'vat_inclusive' => $vat,
            'discount_amount' => $discount,
            'discount_note' => $data['discount_note'] ?? null,
            'created_by' => $request->user()->id,
            'status' => 'active',
        ], $data['decoration_methods'] ?? [], $data['cutting_type']);

        foreach ($sizes as $size => $qty) {
            $order->items()->create(['size' => $size, 'quantity' => $qty]);
        }

        // Design comes first: create the draft job order now so the client
        // reference can be attached right away, then the layout is sent to an
        // artist — no downpayment needed yet.
        $order->jobOrder()->create([
            'status' => 'draft',
            'created_by' => $request->user()->id,
        ]);

        return redirect()
            ->route('orders.show', $order)
            ->with('success', "Order {$order->order_number} created for {$client->fullName()} ({$order->quantity} pcs). Upload the client reference, then send it to an artist for the layout.");
    }

    public function edit(ProductionOrder $order): View
    {
        $this->assertOrderVisible($order);
        abort_unless(in_array($order->status, ['active', 'on_hold'], true), 403);

        $order->load('client');

        // If the stored price isn't the standard tier price, it was a custom
        // override — pre-open that field so the edit form preserves it.
        $std = \App\Services\PricingService::quote($order->product_type ?? '', $order->quantity, (bool) $order->back_pocket);
        $priceOverride = null;
        if ($order->unit_price !== null && ($std['unit'] === null || (float) $order->unit_price !== (float) $std['unit'])) {
            $priceOverride = (float) $order->unit_price;
        }

        return view('orders.edit', [
            'order' => $order,
            'priceOverride' => $priceOverride,
            'decorationMethods' => ProductionOrder::DECORATION_METHODS,
            'cuttingTypes' => ProductionOrder::CUTTING_TYPES,
            'products' => \App\Services\PricingService::products(),
            'backPocketFee' => \App\Services\PricingService::backPocketFee(),
        ]);
    }

    public function update(Request $request, ProductionOrder $order): RedirectResponse
    {
        $this->assertOrderVisible($order);
        abort_unless(in_array($order->status, ['active', 'on_hold'], true), 403);

        $data = $request->validate([
            'client_name' => ['required', 'string', 'max:255'],
            'client_last_name' => ['required', 'string', 'max:255'],
            'client_contact' => ['required', 'string', 'max:255'],
            'client_company' => ['nullable', 'string', 'max:255'],
            'client_office_address' => ['required', 'string', 'max:255'],
            'client_delivery_address' => ['required', 'string', 'max:255'],
            'client_tin' => ['nullable', 'string', 'max:50'],

            'description' => ['nullable', 'string', 'max:1000'],
            'due_date' => ['required', 'date'],

            // How many of each size (the inquiry breakdown). Total = quantity.
            'sizes' => ['required', 'array'],
            'sizes.*' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'other_size' => ['nullable', 'string', 'max:50'],
            'other_size_qty' => ['nullable', 'integer', 'min:0', 'max:100000'],

            'vat_inclusive' => ['nullable', 'boolean'],
            'discount_amount' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'discount_note' => ['nullable', 'string', 'max:255'],

            'product_type' => ['required', 'string', 'in:'.implode(',', [...array_keys(\App\Services\PricingService::products()), '__other__'])],
            'product_type_custom' => ['nullable', 'required_if:product_type,__other__', 'string', 'max:100'],
            'back_pocket' => ['nullable', 'boolean'],
            'back_pocket_qty' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'massprod_priority' => ['nullable', 'boolean'],
            'skip_sample' => ['nullable', 'boolean'],
            'unit_price_override' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'rush' => ['nullable', 'boolean'],
            'rush_fee' => ['nullable', 'required_if:rush,1', 'numeric', 'min:0', 'max:10000000'],
        ], [
            'client_last_name.required' => "Enter the client's last name.",
            'client_contact.required' => 'Enter the contact number.',
            'client_office_address.required' => 'Enter the office address.',
            'client_delivery_address.required' => 'Enter the delivery address.',
            'rush_fee.required_if' => 'Enter the rush fee, or untick Rush order.',
        ]);

        if ($data['product_type'] === '__other__') {
            $data['product_type'] = \Illuminate\Support\Str::title(trim($data['product_type_custom']));
        }

        $sizes = $this->collectSizes($data);

        if ($sizes->isEmpty()) {
            return back()->withInput()->withErrors(['sizes' => 'Enter how many pieces for at least one size.']);
        }

        $data['quantity'] = $sizes->sum();

        // This order's own pieces don't count against its due date.
        if ($msg = $this->capacityError($data['due_date'], $data['quantity'], $order->id)) {
            return back()->withInput()->withErrors(['due_date' => $msg]);
        }

        $backPocket = (bool) ($data['back_pocket'] ?? false);
        $backPocketQty = $backPocket ? (int) ($data['back_pocket_qty'] ?? $data['quantity']) : null;
        $quote = \App\Services\PricingService::quote($data['product_type'], $data['quantity'], $backPocket, $backPocketQty);
        $backPocketQty = $backPocket ? $quote['back_pocket_qty'] : null;
        $backPocketAmount = $quote['back_pocket_amount'];
        $unitPrice = ! empty($data['unit_price_override']) ? (float) $data['unit_price_override'] : $quote['unit'];
        $vat = (bool) ($data['vat_inclusive'] ?? false);
        $discount = (float) ($data['discount_amount'] ?? 0);
        $rush = (bool) ($data['rush'] ?? false);
        $rushFee = $rush ? round((float) ($data['rush_fee'] ?? 0), 2) : null;
        $totalPrice = ProductionOrder::computeTotal(
            $unitPrice, $data['quantity'], $discount, $vat, $backPocketAmount, (float) $rushFee
        );

        // Keep the linked client's details fixed up too.
        $order->client?->update([
            'name' => $data['client_name'],
            'last_name' => $data['client_last_name'],
            'contact_number' => $data['client_contact'] ?? null,
            'company' => $data['client_company'] ?? null,
            'office_address' => $data['client_office_address'] ?? null,
            'delivery_address' => $data['client_delivery_address'] ?? null,
            'tin' => $data['client_tin'] ?? null,
        ]);

        // Replace the size breakdown with what was submitted, but keep the
        // per-line descriptions (typed on the job order) for sizes that remain.
        $keepDesc = $order->items()->pluck('description', 'size');
        $order->items()->delete();
        foreach ($sizes as $size => $qty) {
            $order->items()->create([
                'size' => $size,
                'quantity' => $qty,
                'description' => $keepDesc[$size] ?? null,
            ]);
        }

        // Decoration, cutting & production specs live on the job order, so an
        // order edit only touches client/product/price/sizes.
        $routingNote = '';

        // NOTE: description is edited on the job order sheet, not here — don't touch it.
        $order->update([
            'customer_name' => trim($data['client_name'].' '.$data['client_last_name']),
            'product_type' => $data['product_type'],
            'quantity' => $data['quantity'],
            'due_date' => $data['due_date'],
            'back_pocket' => $backPocket,
            'back_pocket_qty' => $backPocketQty,
            'unit_price' => $unitPrice,
            'total_price' => $totalPrice,
            'vat_inclusive' => $vat,
            'discount_amount' => $discount,
            'discount_note' => $data['discount_note'] ?? null,
            'rush' => $rush,
            'rush_fee' => $rushFee,
        ]);

        return redirect()->route('orders.show', $order)->with('success', "Order {$order->order_number} updated.".$routingNote);
    }

    /**
     * Release the LAYOUT to an artist — the first design step. This happens right
     * after the inquiry, before any payment or job-order details, so the client
     * can review and approve the layout before committing.
     */
    public function sendForLayout(Request $request, ProductionOrder $order): RedirectResponse
    {
        $this->assertOrderVisible($order);
        $order->load(['jobOrder.referenceFiles', 'tasks']);
        abort_unless($order->jobOrder, 404);

        // Notes captured from the client for the artist to work the layout from.
        $data = $request->validate([
            'reference_note' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($request->has('reference_note')) {
            $order->jobOrder->update(['reference_note' => $data['reference_note'] ?? null]);
        }

        // Notes can still be adjusted while the artist is already working.
        if ($order->layoutReleased()) {
            return back()->with('success', 'Notes for the artist saved.');
        }

        // The artist needs something to work from — the ChatGPT design output,
        // written notes, or both.
        $hasDesign = $order->jobOrder->referenceFiles()->where('kind', 'output')->exists();

        if (! $hasDesign && blank($data['reference_note'] ?? null)) {
            return back()->withErrors(['layout' => 'Upload the ChatGPT design output or add notes for the artist before sending.']);
        }

        // unlockStage assigns a present artist and keeps them across the design
        // steps (layout → final mockup → template).
        $order->unlockStage(ProductionOrder::STAGE_LAYOUT);

        return redirect()->route('orders.show', $order)
            ->with('success', 'Sent to the artist for the layout. The client can review it once the artist submits.');
    }

    /** How many pieces are already booked for a due date (live hint on the form). */
    public function capacity(Request $request)
    {
        $date = $request->query('date');

        if (blank($date) || ! strtotime($date)) {
            return response()->json(['booked' => 0, 'capacity' => ProductionOrder::DAILY_CAPACITY, 'remaining' => ProductionOrder::DAILY_CAPACITY]);
        }

        $booked = ProductionOrder::bookedQtyForDate($date, $request->integer('except') ?: null);

        return response()->json([
            'booked' => $booked,
            'capacity' => ProductionOrder::DAILY_CAPACITY,
            'remaining' => max(0, ProductionOrder::DAILY_CAPACITY - $booked),
        ]);
    }

    public function show(ProductionOrder $order): View
    {
        $this->assertOrderVisible($order);
        $order->load(['tasks.assignee', 'tasks.files', 'creator', 'jobOrder.referenceFiles', 'materialRequests.item', 'payments']);

        return view('orders.show', [
            'order' => $order,
            'agents' => $this->assignableUsers(),
        ]);
    }

    public function updateStatus(Request $request, ProductionOrder $order): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:hold,resume,cancel'],
        ]);

        match ($data['action']) {
            'hold' => $order->hold(),
            'resume' => $order->resume(),
            'cancel' => $order->cancel(),
        };

        return back()->with('success', 'Order '.$order->order_number.' is now '.$order->statusLabel().'.');
    }

    /** The client reference files on their own page. */
    public function references(ProductionOrder $order): View
    {
        $this->assertOrderVisible($order);
        $order->load('jobOrder.referenceFiles');

        return view('orders.references', ['order' => $order]);
    }

    /** The job order sheet — what the artist works from and the leader reviews. */
    public function jobOrder(ProductionOrder $order): View
    {
        $this->assertOrderVisible($order);
        $order->load(['jobOrder.referenceFiles', 'client', 'creator', 'items', 'tasks.assignee', 'tasks.files']);

        return view('orders.job-order', ['order' => $order]);
    }

    /** Display the mockup image in a centered, focused view. */
    public function mockup(ProductionOrder $order): View
    {
        $this->assertOrderVisible($order);
        $order->load(['tasks.files', 'creator']);

        return view('orders.mockup', ['order' => $order]);
    }

    /** Production staff, with their team so each step lists only its own people. */
    private function assignableUsers()
    {
        return User::agents()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'job_role']);
    }
}
