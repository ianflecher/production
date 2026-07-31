<?php

namespace App\Http\Controllers;

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
    public function index(Request $request): View
    {
        // Sort orders by workflow priority. FIELD() is MySQL-only, so fall back
        // to a portable CASE on other drivers (e.g. SQLite in tests).
        $statusOrder = DB::getDriverName() === 'mysql'
            ? "FIELD(status, 'active', 'on_hold', 'complete', 'cancelled')"
            : "CASE status WHEN 'active' THEN 1 WHEN 'on_hold' THEN 2 WHEN 'complete' THEN 3 WHEN 'cancelled' THEN 4 ELSE 5 END";

        $orders = ProductionOrder::with('tasks')
            ->orderByRaw($statusOrder)
            ->orderByDesc('id')
            // Account officers see only their own orders; leaders/admin see all.
            ->when($request->user()->isSales(), fn ($q) => $q->where('created_by', $request->user()->id))
            ->get();

        return view('orders.index', ['orders' => $orders]);
    }

    /**
     * Account officers may only touch the orders they created. Leaders and the
     * super admin can access every order.
     */
    private function assertOrderVisible(ProductionOrder $order): void
    {
        $user = auth()->user();

        if ($user->isSales() && $order->created_by !== $user->id) {
            abort(403);
        }
    }

    public function create(): View
    {
        return view('orders.create', [
            'clients' => Client::orderBy('name')->get(),
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

            // Existing client OR a new one typed in.
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'client_name' => ['required_without:client_id', 'nullable', 'string', 'max:255'],
            'client_contact' => ['nullable', 'string', 'max:255'],
            'client_company' => ['nullable', 'string', 'max:255'],
            'client_office_address' => ['nullable', 'string', 'max:255'],
            'client_delivery_address' => ['nullable', 'string', 'max:255'],
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

        // Total = (unit x qty) + back-pocket charge, less the discount, then +12% VAT when ticked.
        $vat = (bool) ($data['vat_inclusive'] ?? false);
        $discount = (float) ($data['discount_amount'] ?? 0);
        $totalPrice = ProductionOrder::computeTotal($unitPrice, $data['quantity'], $discount, $vat, $backPocketAmount);

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
                'created_by' => $request->user()->id,
            ]);

        $order = ProductionOrder::createJobOrder([
            'order_number' => $data['order_number'],
            'client_id' => $client->id,
            'customer_name' => $client->name,
            'product_type' => $data['product_type'],
            'description' => $data['description'] ?? null,
            'quantity' => $data['quantity'],
            'due_date' => $data['due_date'],
            'back_pocket' => $backPocket,
            'back_pocket_qty' => $backPocketQty,
            'massprod_priority' => (bool) ($data['massprod_priority'] ?? false),
            'skip_sample' => (bool) ($data['skip_sample'] ?? false),
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
            ->with('success', "Order {$order->order_number} created for {$client->name} ({$order->quantity} pcs). Upload the client reference, then send it to an artist for the layout.");
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
            'client_contact' => ['nullable', 'string', 'max:255'],
            'client_company' => ['nullable', 'string', 'max:255'],
            'client_office_address' => ['nullable', 'string', 'max:255'],
            'client_delivery_address' => ['nullable', 'string', 'max:255'],
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
        $totalPrice = ProductionOrder::computeTotal($unitPrice, $data['quantity'], $discount, $vat, $backPocketAmount);

        // Keep the linked client's details fixed up too.
        $order->client?->update([
            'name' => $data['client_name'],
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
            'customer_name' => $data['client_name'],
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
        ]);

        return redirect()->route('orders.show', $order)->with('success', "Order {$order->order_number} updated.".$routingNote);
    }

    public function recordPayment(Request $request, ProductionOrder $order): RedirectResponse
    {
        $this->assertOrderVisible($order);

        $data = $request->validate([
            'portion' => ['required', 'in:half,full,balance,partial'],
            'amount' => ['nullable', 'numeric', 'min:1', 'max:100000000', 'required_if:portion,partial'],
            'method' => ['required', 'in:'.implode(',', \App\Models\Payment::METHODS)],
            'reference' => ['nullable', 'string', 'max:255'],
            // Proof is mandatory — no payment is recorded without it.
            // Images/PDF only, never executables. No app-side size cap; PHP's
            // upload_max_filesize (40M) is the practical ceiling.
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf'],
        ], [
            'proof.required' => 'A picture/screenshot of the payment proof is required before the payment can be recorded.',
        ]);

        if ($order->total_price === null) {
            return back()->withErrors(['payment' => 'Set a price first (Edit order) before recording a payment.']);
        }

        // Design-first flow: the downpayment is only collected once the client has
        // approved the layout. Until then there is nothing to pay for yet.
        if (! $order->layoutApproved()) {
            return back()->withErrors(['payment' => 'Record the downpayment after the client approves the layout.']);
        }

        $total = (float) $order->total_price;
        $balance = $order->balance() ?? 0;
        $wasFirst = ! $order->hasDownpayment();

        // Partial top-ups are only allowed after a downpayment has been recorded.
        if ($data['portion'] === 'partial' && $wasFirst) {
            return back()->withErrors(['payment' => 'Record the downpayment first, then you can add partial payments.']);
        }

        $amount = match ($data['portion']) {
            'half' => round($total / 2, 2),
            'full' => $wasFirst ? $total : $balance,
            'balance' => $balance,
            // A custom amount, never more than what's still owed.
            'partial' => min(round((float) ($data['amount'] ?? 0), 2), $balance),
        };

        if ($amount <= 0) {
            return back()->withErrors(['payment' => 'Nothing left to pay — this order is fully paid.']);
        }

        $kind = $wasFirst ? ($data['portion'] === 'full' ? 'full' : 'downpayment') : 'payment';

        // Proof files stay on this PC in storage/app (never in the public folder)
        // and are only served through an authenticated route.
        $proofPath = null;
        $proofName = null;
        if ($request->hasFile('proof')) {
            $file = $request->file('proof');
            $proofName = $file->getClientOriginalName();
            $proofPath = $file->store('payment-proofs', 'local');
        }

        $order->recordPayment([
            'amount' => $amount,
            'method' => $data['method'] ?? null,
            'reference' => $data['reference'] ?? null,
            'proof_path' => $proofPath,
            'proof_name' => $proofName,
            'kind' => $kind,
            'recorded_by' => $request->user()->id,
        ]);

        // Safety net: the draft job order is normally created at inquiry, but make
        // sure one exists before the officer fills it in.
        if ($wasFirst && ! $order->jobOrder) {
            $order->jobOrder()->create([
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);
        }

        // Flow: layout approved → downpayment → FILL JOB ORDER → send → artist
        // makes the final mockup → template → leader. The first payment opens the
        // job order for the account officer to fill in right away.
        if ($wasFirst) {
            return redirect()->route('job-orders.edit', $order)->with(
                'success',
                'Downpayment recorded (₱'.number_format($amount, 2).'). Fill in the job order below, then send it to the artist.'
            );
        }

        return back()->with('success', 'Payment recorded (₱'.number_format($amount, 2).').');
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

    /**
     * A client-facing document (DR or PQ). Created on first open, pre-filled
     * from the order; everything else is typed by the account officer.
     * Available before AND after payment.
     */
    public function document(ProductionOrder $order, string $type): View
    {
        $this->assertOrderVisible($order);
        abort_unless(array_key_exists($type, \App\Models\OrderDocument::TYPES), 404);

        $defaults = \App\Models\OrderDocument::defaultsFor($order, $type);
        $doc = $order->documents()->firstWhere('type', $type);

        if (! $doc) {
            $doc = $order->documents()->create([
                'type' => $type,
                'number' => $defaults['number'],
                'items' => $defaults['items'],
                'fields' => $defaults['fields'],
                'created_by' => auth()->id(),
            ]);
        } else {
            // The job order is filled AFTER this document first appears, so top up
            // anything still blank (print type, materials…) without ever
            // overwriting what the officer has typed.
            $fields = $doc->fields ?? [];
            $filled = false;

            foreach (array_filter($defaults['fields'], fn ($v) => $v !== null && $v !== '') as $k => $v) {
                if (! isset($fields[$k]) || $fields[$k] === '' || $fields[$k] === null) {
                    $fields[$k] = $v;
                    $filled = true;
                }
            }

            if ($filled) {
                $doc->update(['fields' => $fields]);
            }
        }

        $order->load('tasks.files');

        return view('orders.document', ['order' => $order, 'doc' => $doc]);
    }

    public function saveDocument(Request $request, ProductionOrder $order, string $type): RedirectResponse
    {
        $this->assertOrderVisible($order);
        abort_unless(array_key_exists($type, \App\Models\OrderDocument::TYPES), 404);

        $doc = $order->documents()->where('type', $type)->firstOrFail();

        $data = $request->validate([
            'number' => ['nullable', 'string', 'max:50'],
            'fields' => ['nullable', 'array'],
            'fields.*' => ['nullable', 'string', 'max:1000'],
            'items' => ['nullable', 'array'],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.size' => ['nullable', 'string', 'max:50'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'items.*.addon' => ['nullable', 'boolean'],
        ]);

        // Drop rows with nothing on them.
        $items = collect($data['items'] ?? [])
            ->filter(fn ($r) => filled($r['description'] ?? null) || filled($r['size'] ?? null) || filled($r['quantity'] ?? null))
            ->values()
            ->all();

        // Merge, don't replace: the "before payment" copy doesn't render the
        // payment/signature fields, so a save from there must not wipe them.
        // Fields that ARE on the form still overwrite (including being cleared).
        $fields = array_merge($doc->fields ?? [], $data['fields'] ?? []);
        $fields = array_filter($fields, fn ($v) => $v !== null && $v !== '');

        $doc->update([
            'number' => $data['number'] ?? $doc->number,
            'fields' => $fields,
            'items' => $items,
        ]);

        // The document is the pricing source, so its grand total drives the order's
        // Total and payment balance — this is how extra products added on the
        // document flow into the order's payment section. The Price Quotation adds
        // 12% VAT; the Delivery Receipt (no VAT) uses the plain line total.
        $net = 0.0;
        foreach ($items as $row) {
            $net += (float) ($row['quantity'] ?? 0) * (float) ($row['unit_price'] ?? 0);
        }
        $gross = $type === \App\Models\OrderDocument::TYPE_PQ ? $net * 1.12 : $net;
        $synced = false;
        if ($gross > 0) {
            $order->update([
                'total_price' => round($gross, 2),
                'vat_inclusive' => $type === \App\Models\OrderDocument::TYPE_PQ,
            ]);
            $synced = true;
        }

        return redirect()->route('orders.document', [$order, $type])
            ->with('success', $doc->typeLabel().' saved.'.($synced ? ' Order total updated to ₱'.number_format(round($order->fresh()->total_price ?? 0, 2), 2).'.' : ''));
    }

    /**
     * Re-pull everything from the order (prices, sizes, job order specs),
     * discarding typed values. Used when the order changed after the document
     * was first made.
     */
    public function refreshDocument(ProductionOrder $order, string $type): RedirectResponse
    {
        $this->assertOrderVisible($order);
        abort_unless(array_key_exists($type, \App\Models\OrderDocument::TYPES), 404);

        $doc = $order->documents()->where('type', $type)->firstOrFail();
        $defaults = \App\Models\OrderDocument::defaultsFor($order, $type);

        $doc->update([
            'items' => $defaults['items'],
            'fields' => array_filter($defaults['fields'], fn ($v) => $v !== null && $v !== ''),
        ]);

        return redirect()->route('orders.document', [$order, $type])
            ->with('success', 'Re-filled from the order — typed changes were replaced.');
    }

    /** Attach the contract / payment proof / signed copy onto the document. */
    public function uploadDocumentFile(Request $request, ProductionOrder $order, string $type): RedirectResponse
    {
        $this->assertOrderVisible($order);
        abort_unless(array_key_exists($type, \App\Models\OrderDocument::TYPES), 404);

        $doc = $order->documents()->where('type', $type)->firstOrFail();

        $request->validate([
            'attachments' => ['required', 'array'],
            'attachments.*' => ['file', 'mimes:jpg,jpeg,png,webp,gif,pdf', 'max:65536'],
        ], ['attachments.required' => 'Choose at least one file.']);

        $files = $doc->attachmentList();

        foreach ($request->file('attachments') as $file) {
            $files[] = [
                'path' => $file->store('order-documents', 'local'),
                'name' => $file->getClientOriginalName(),
                'mime' => $file->getClientMimeType(),
            ];
        }

        $doc->update(['attachments' => $files]);

        return back()->with('success', 'Attached to the document.');
    }

    public function deleteDocumentFile(Request $request, ProductionOrder $order, string $type, int $index): RedirectResponse
    {
        $this->assertOrderVisible($order);
        abort_unless(array_key_exists($type, \App\Models\OrderDocument::TYPES), 404);

        $doc = $order->documents()->where('type', $type)->firstOrFail();
        $files = $doc->attachmentList();

        if (isset($files[$index])) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($files[$index]['path']);
            unset($files[$index]);
            $doc->update(['attachments' => array_values($files)]);
        }

        return back()->with('success', 'Attachment removed.');
    }

    /** Serve an attachment inline (officers on their own order, plus leaders). */
    public function viewDocumentFile(ProductionOrder $order, string $type, int $index)
    {
        $this->assertOrderVisible($order);

        $doc = $order->documents()->where('type', $type)->firstOrFail();
        $file = $doc->attachmentList()[$index] ?? abort(404);

        abort_unless(\Illuminate\Support\Facades\Storage::disk('local')->exists($file['path']), 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->response($file['path'], $file['name']);
    }

    /** Serve the flatlay photo (stored on the private disk). */
    public function viewDocumentFlatlay(ProductionOrder $order, string $type)
    {
        $this->assertOrderVisible($order);
        abort_unless(array_key_exists($type, \App\Models\OrderDocument::TYPES), 404);

        $doc = $order->documents()->where('type', $type)->firstOrFail();
        $flat = $doc->flatlay;

        abort_unless(is_array($flat) && ! empty($flat['path']), 404);
        abort_unless(\Illuminate\Support\Facades\Storage::disk('local')->exists($flat['path']), 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->response(
            $flat['path'],
            $flat['name'] ?? 'flatlay'
        );
    }

    /** Upload flatlay image for the document. */
    public function uploadDocumentFlatlay(\Illuminate\Http\Request $request, ProductionOrder $order, string $type): RedirectResponse
    {
        $this->assertOrderVisible($order);
        abort_unless(array_key_exists($type, \App\Models\OrderDocument::TYPES), 404);

        $request->validate([
            'flatlay' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:65536'],
        ], ['flatlay.required' => 'Please choose a flatlay image.']);

        $doc = $order->documents()->where('type', $type)->firstOrFail();
        $file = $request->file('flatlay');

        $path = $file->store('order-documents/flatlays', 'local');

        $flatlay = [
            'path' => $path,
            'name' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
        ];

        $doc->update(['flatlay' => $flatlay]);

        return redirect()->route('orders.document', [$order, $type])
            ->with('success', 'Flatlay image uploaded successfully.');
    }

    /** The client design questionnaire → copy-paste ChatGPT prompt. */
    public function designBrief(ProductionOrder $order): View
    {
        $this->assertOrderVisible($order);
        $order->load('jobOrder.referenceFiles');
        abort_unless($order->jobOrder, 404);

        // Orders made before the token feature (or with an expired link) get a
        // fresh token here so the share link always generates.
        if (! $order->brief_token) {
            $order->regenerateBriefLink();
        }

        $answers = $order->jobOrder->design_brief ?? [];

        return view('orders.design-brief', [
            'order' => $order,
            'questions' => \App\Services\DesignBrief::questions(),
            'answers' => $answers,
            'prompt' => $answers ? \App\Services\DesignBrief::toPrompt($answers, $order) : null,
            // Shareable, login-free link the client can fill in themselves — a
            // clean random-token URL (no signature) that expires after 30 days.
            'clientLink' => route('client.design-brief', ['order' => $order]),
            'clientLinkExpiresAt' => $order->brief_expires_at,
            // When set, the client already submitted and the link is now closed.
            'clientSubmittedAt' => $order->jobOrder->client_brief_submitted_at,
        ]);
    }

    /** Reopen the single-use client link so the client can submit once more. */
    public function reopenClientBrief(ProductionOrder $order): RedirectResponse
    {
        $this->assertOrderVisible($order);
        $order->load('jobOrder');
        abort_unless($order->jobOrder, 404);

        $order->jobOrder->update(['client_brief_submitted_at' => null]);

        return redirect()->route('orders.design-brief', $order)
            ->with('success', 'Client form reopened — the link works again for one more submission.');
    }

    public function saveDesignBrief(Request $request, ProductionOrder $order): RedirectResponse
    {
        $this->assertOrderVisible($order);
        $order->load('jobOrder');
        abort_unless($order->jobOrder, 404);

        $questions = \App\Services\DesignBrief::questions();

        $data = $request->validate([
            'brief' => ['nullable', 'array'],
            'brief.*' => ['nullable', 'string', 'max:2000'],
            // Files attached under the peg / logo questions.
            'files' => ['nullable', 'array'],
            'files.*' => ['nullable', 'array'],
            'files.*.*' => ['file', 'mimes:jpg,jpeg,png,webp,gif,pdf,ai,psd,eps,cdr,zip', 'max:65536'],
        ]);

        // Keep only questions we actually asked, and drop blanks.
        $answers = collect($data['brief'] ?? [])
            ->only(array_keys($questions))
            ->filter(fn ($v) => filled($v))
            ->map(fn ($v) => trim($v))
            ->all();

        $order->jobOrder->update(['design_brief' => $answers ?: null]);

        // Uploads live with the client reference files, so the artist gets them.
        $kinds = collect($questions)->pluck('files')->filter()->all();

        foreach ($request->file('files', []) as $kind => $files) {
            if (! in_array($kind, $kinds, true)) {
                continue;
            }

            foreach ($files as $file) {
                $order->jobOrder->referenceFiles()->create([
                    'path' => $file->store('job-order-refs', 'local'),
                    'original_name' => $file->getClientOriginalName(),
                    'kind' => $kind,
                    'mime' => $file->getClientMimeType(),
                    'size' => $file->getSize(),
                    'uploaded_by' => $request->user()->id,
                ]);
            }
        }

        return redirect()->route('orders.design-brief', $order)
            ->with('success', 'Design brief saved. Copy the prompt below into ChatGPT to generate the design.');
    }

    public function show(ProductionOrder $order): View
    {
        $this->assertOrderVisible($order);
        $order->load(['tasks.assignee', 'tasks.files', 'creator', 'jobOrder.referenceFiles', 'materialRequests.item']);

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

    /** Ensure a draft job order exists, then go to the fill-in form. */
    public function createJobOrder(Request $request, ProductionOrder $order): RedirectResponse
    {
        $this->assertOrderVisible($order);

        if ($order->jobOrder === null) {
            $order->jobOrder()->create([
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);
        }

        return redirect()->route('job-orders.edit', $order);
    }

    /** Backup manual-create endpoint (job orders are normally auto-created on downpayment). */
    public function storeJobOrder(Request $request, ProductionOrder $order): RedirectResponse
    {
        return $this->createJobOrder($request, $order);
    }

    public function editJobOrder(ProductionOrder $order): View
    {
        $this->assertOrderVisible($order);
        $order->load(['jobOrder', 'items', 'client', 'creator', 'tasks.assignee']);
        abort_unless($order->jobOrder, 404);

        return view('job-orders.edit', [
            'order' => $order,
            'jobOrder' => $order->jobOrder,
            'suggest' => JobOrder::fieldSuggestions(),
        ]);
    }

    /** PAGE 1 — the fields printed on the job order sheet (the yellow boxes). */
    public function updateJobOrder(\Illuminate\Http\Request $request, ProductionOrder $order): \Illuminate\Http\RedirectResponse
    {
        $this->assertOrderVisible($order);
        $order->load('jobOrder');
        abort_unless($order->jobOrder, 404);

        $data = $request->validate([
            // Header
            'fb_viber_gc' => ['nullable', 'string', 'max:255'],
            // Production (yellow) — print type is free text (with suggestions).
            'print_type' => ['required', 'string', 'max:255'],
            'printer' => ['required', 'in:'.implode(',', array_keys(JobOrder::PRINTERS))],
            'fabric' => ['required', 'string', 'max:255'],
            'free_logo_sticker' => ['nullable', 'string', 'max:255'],
            // Sewing (yellow)
            'neck' => ['nullable', 'string', 'max:255'],
            'cuff_arm_sleeves' => ['nullable', 'string', 'max:255'],
            'neck_label' => ['nullable', 'string', 'max:255'],
            'bottom_hem' => ['nullable', 'string', 'max:255'],
            'ic_placement' => ['nullable', 'string', 'max:255'],
            // Quality check (yellow)
            'packaging' => ['nullable', 'string', 'max:255'],
            // Agent notes
            'special_instructions' => ['nullable', 'string', 'max:5000'],
            // Per-line description: one per size row (keyed by order item id).
            'item_desc' => ['nullable', 'array'],
            'item_desc.*' => ['nullable', 'string', 'max:255'],
        ]);

        // Was this a fix requested by the leader? Clearing the note sends the
        // (already-made) package straight back to the leader's queue.
        $wasLeaderFix = filled($order->jobOrder->leader_note);

        $order->jobOrder->update(collect($data)->except('item_desc')->all() + ['leader_note' => null]);

        // Save each line's description, then mirror the distinct lines into the
        // order-level brief so the artist/summary views still read sensibly.
        $order->load('items');
        foreach ($order->items as $item) {
            $item->update(['description' => $data['item_desc'][$item->id] ?? null]);
        }
        $brief = $order->items->pluck('description')->filter()->unique()->implode(', ');
        $order->update(['description' => $brief !== '' ? $brief : null]);

        // A named free-logo sticker means the sticker production step is needed.
        // A blank field — or a "no sticker" placeholder like n/a / none / - —
        // must NOT create a sticker step, so only a real name turns it on.
        $sticker = trim((string) ($data['free_logo_sticker'] ?? ''));
        $newSticker = $sticker !== ''
            && ! in_array(strtolower($sticker), ['n/a', 'na', 'n.a.', 'none', 'no', 'nil', '-', '--', 'x', 'wala', 'walang sticker'], true);
        if ($newSticker !== (bool) $order->needs_sticker) {
            $order->update(['needs_sticker' => $newSticker]);
        }

        // Choosing a print type already implies a press and a cutting method, so
        // put them straight into the pipeline instead of leaving the order with
        // no decoration/cutting until someone opens the production-details page.
        $order->refresh()->load('jobOrder');

        if ($order->canEditRouting()) {
            $config = \App\Models\JobOrder::printTypeConfig($order->jobOrder->print_type);

            if (! $order->cutting_type && ($config['cutting'] ?? null)) {
                $order->update(['cutting_type' => $config['cutting']]);
            }

            // Fabric press auto-matches the print type (embroidery print type =>
            // embroidery). Set it now so its step is in the pipeline from the start;
            // it stays overridable on the production-details page.
            if (! $order->jobOrder->fabric_press) {
                $fp = $order->jobOrder->defaultFabricPress();
                $order->jobOrder->update([
                    'fabric_press' => $fp,
                    'needs_embroidery' => $fp === 'embroidery' ? true : (bool) $order->jobOrder->needs_embroidery,
                ]);
            }

            $order->refresh()->rebuildPipeline($order->decoration_methods ?? [], $order->cutting_type);
        }

        // A leader fix goes straight back to them; a first fill continues the flow.
        if ($wasLeaderFix) {
            return redirect()->route('orders.show', $order)
                ->with('success', 'Job order corrected — sent back to the leader for checking.');
        }

        return redirect()->route('orders.job-order', $order)->with('success', 'Job order saved. Double-check it below, then continue to the production details.');
    }

    /** The whole job package as ONE document: mockup, template, job order,
     *  production details — one printed page each. */
    public function completeJobOrder(ProductionOrder $order): View
    {
        $this->assertOrderVisible($order);
        $order->load(['jobOrder.referenceFiles', 'items', 'client', 'creator', 'tasks.files']);
        abort_unless($order->jobOrder, 404);

        return view('job-orders.complete', [
            'order' => $order,
            'jobOrder' => $order->jobOrder,
            'rawMaterialSuggestions' => JobOrder::fieldSuggestions()['raw_materials'] ?? [],
        ]);
    }

    /** Production details (raw materials + cutting). Saves via updateProductionDetails. */
    public function productionJobOrder(ProductionOrder $order): View
    {
        $this->assertOrderVisible($order);
        $order->load(['jobOrder.referenceFiles', 'items', 'client', 'creator']);
        abort_unless($order->jobOrder, 404);

        return view('job-orders.production', [
            'order' => $order,
            'jobOrder' => $order->jobOrder,
            'rawMaterialSuggestions' => JobOrder::fieldSuggestions()['raw_materials'] ?? [],
        ]);
    }

    public function updateProductionDetails(\Illuminate\Http\Request $request, ProductionOrder $order): RedirectResponse
    {
        $this->assertOrderVisible($order);
        $order->load('jobOrder');
        abort_unless($order->jobOrder, 404);

        // Both press dropdowns accept the real presses OR embroidery.
        $pressKeys = array_keys(\App\Models\JobOrder::pressOptions());

        $data = $request->validate([
            'raw_materials' => ['nullable', 'array'],
            'raw_materials.*' => ['nullable', 'string', 'max:255'],
            'cutting_type' => ['nullable', 'in:'.implode(',', array_keys(ProductionOrder::CUTTING_TYPES))],
            // Fabric press (required, merges the print onto the fabric) and the
            // decoration — a checkbox toggle; when on it's a press OR embroidery.
            'fabric_press' => ['required', 'in:'.implode(',', $pressKeys)],
            'decoration_on' => ['nullable', 'boolean'],
            'press' => ['nullable', 'in:'.implode(',', $pressKeys)],
            'embroidery_note' => ['nullable', 'string', 'max:500'],
        ]);

        // Decoration off → no decoration press at all. On → the chosen method.
        $fabricPress = $data['fabric_press'];
        $decoOn = (bool) ($data['decoration_on'] ?? false);
        $decoPress = $decoOn ? ($data['press'] ?? null) : null;
        // Embroidery is needed when EITHER slot is set to embroidery.
        $needsEmbroidery = $fabricPress === 'embroidery' || $decoPress === 'embroidery';

        $decorationChanged = $fabricPress !== $order->jobOrder->fabric_press
            || $decoPress !== $order->jobOrder->press
            || $needsEmbroidery !== (bool) $order->jobOrder->needs_embroidery;

        // Drop blank raw-material rows so only real entries are stored. (The
        // client reference and artist notes are captured at the layout step.)
        $order->jobOrder->update([
            'raw_materials' => array_values(array_filter($data['raw_materials'] ?? [], fn ($v) => filled($v))),
            'fabric_press' => $fabricPress,
            'press' => $decoPress,
            'needs_embroidery' => $needsEmbroidery,
            'embroidery_note' => $needsEmbroidery ? ($data['embroidery_note'] ?? null) : null,
        ]);

        // Changing the press / embroidery changes the decoration steps, so rebuild
        // the routing — allowed while decoration and cutting haven't started.
        if ($decorationChanged && $order->canEditRouting()) {
            $order->refresh()->rebuildPipeline($order->decoration_methods ?? [], $order->cutting_type);
        }

        // If the Raw materials step is already open (the leader has approved the
        // package), keep its stock requests in step with edits made here. Before
        // that, the requests are raised on approval instead.
        $rawStepOpen = $order->tasks()
            ->where('department', 'Raw materials')
            ->whereIn('status', \App\Services\Stations::RELEASED)
            ->exists();

        if ($rawStepOpen) {
            $order->refresh()->syncMaterialRequests();
        }

        $newCut = $data['cutting_type'] ?? null;
        $note = 'Production details saved.';

        if ($newCut !== $order->cutting_type) {
            if ($order->canEditRouting()) {
                $order->update(['cutting_type' => $newCut]);
                $order->rebuildPipeline($order->decoration_methods ?? [], $newCut);
                $note .= ' Production steps updated.';
            } else {
                $note .= ' Cutting was NOT changed — cutting has already been done on this order.';
            }
        }

        // The press/embroidery choice is saved either way, but it can only be
        // added to the pipeline while decoration and cutting haven't happened —
        // say so plainly instead of quietly doing nothing.
        if ($decorationChanged && ! $order->canEditRouting()) {
            $note .= ' The press/embroidery is recorded on the job order, but NO decoration step was added —'
                .' this order is already past cutting, so it cannot go back through the press.';
        }

        // Only prompt to send when it hasn't been sent yet.
        if ($order->jobOrder->status === 'draft') {
            $note .= ' Open the job order to send it to the artist.';
        }

        return redirect()->route('orders.show', $order)->with('success', $note);
    }

    public function sendJobOrderToArtist(\Illuminate\Http\Request $request, ProductionOrder $order): \Illuminate\Http\RedirectResponse
    {
        $this->assertOrderVisible($order);
        $order->load(['jobOrder', 'tasks']);
        abort_unless($order->jobOrder, 404);
        abort_unless($order->jobOrder->status === 'draft', 403);

        // Design-first flow: the job order is only sent once the client has
        // approved the layout and the downpayment has been collected.
        if (! $order->layoutApproved()) {
            return back()->withErrors(['job_order' => 'The client must approve the layout before the job order can be sent.']);
        }

        if (! $order->hasDownpayment()) {
            return back()->withErrors(['job_order' => 'Record the downpayment before sending the job order to the artist.']);
        }

        // Production specs must be filled before the artist can use the job order.
        if (! $order->jobOrder->isReadyToSend()) {
            return back()->withErrors(['job_order' => 'Fill in at least Print Type, Printer and Fabric before sending.']);
        }

        // The artist needs the client reference to make the mockup.
        if ($order->jobOrder->referenceFiles()->count() === 0) {
            return back()->withErrors(['job_order' => 'Upload at least one client reference before sending to the artist.']);
        }

        $order->jobOrder->update([
            'status' => 'sent_to_artist',
            'sent_to_artist_by' => $request->user()->id,
            'sent_to_artist_at' => now(),
            'leader_note' => null,   // resolved — the corrected order is on its way
        ]);

        // Sending the job order releases the FINAL MOCKUP to the artist — the
        // layout is already approved, so now they build the production mockup and
        // template for the leader to check.
        $order->unlockStage(ProductionOrder::STAGE_MOCKUP);

        // NOTE: material requests are NOT raised here. They're raised when the
        // leader approves the design package, i.e. when the Raw materials stage
        // opens — see ProductionOrder::unlockStage().

        // Straight to production details so raw materials & cutting always get
        // filled in — it's the next required step, not an optional side trip.
        return redirect()->route('job-orders.production', $order)
            ->with('success', 'Job order sent to the artist. Now fill in the production details (raw materials & cutting) to finish.');
    }

    /** Serve a payment proof file — only to signed-in sales/leaders/admins. */
    public function proof(\App\Models\Payment $payment)
    {
        // Account officers may only view proofs on their own orders.
        $user = auth()->user();
        if ($user->isSales() && $payment->order && $payment->order->created_by !== $user->id) {
            abort(403);
        }

        abort_unless($payment->hasProof() && \Illuminate\Support\Facades\Storage::disk('local')->exists($payment->proof_path), 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->response(
            $payment->proof_path,
            $payment->proof_name ?: basename($payment->proof_path)
        );
    }

    /** Who may see a job order reference: the officer who owns it, leaders, or an assigned artist. */
    private function assertCanSeeReference(\App\Models\JobOrderFile $file): void
    {
        $user = auth()->user();
        $order = $file->jobOrder->order;

        $allowed = $user->isLeader()
            || ($user->isSales() && $order->created_by === $user->id)
            || $order->tasks()->where('assigned_to', $user->id)->exists();

        abort_unless($allowed, 403);
        abort_unless(\Illuminate\Support\Facades\Storage::disk('local')->exists($file->path), 404);
    }

    public function viewReferenceFile(\App\Models\JobOrderFile $file)
    {
        $this->assertCanSeeReference($file);

        return \Illuminate\Support\Facades\Storage::disk('local')->response($file->path, $file->original_name);
    }

    public function downloadReferenceFile(\App\Models\JobOrderFile $file)
    {
        $this->assertCanSeeReference($file);

        return \Illuminate\Support\Facades\Storage::disk('local')->download($file->path, $file->original_name);
    }

    public function uploadReferenceFile(Request $request, ProductionOrder $order): RedirectResponse
    {
        $this->assertOrderVisible($order);
        $order->load('jobOrder');
        abort_unless($order->jobOrder, 404);

        $data = $request->validate([
            'reference_files' => ['required', 'array'],
            'reference_files.*' => ['file', 'mimes:jpg,jpeg,png,webp,gif,pdf,ai,psd,eps,cdr,zip', 'max:65536'],
            // "output" = the design saved from ChatGPT (what the artist works from).
            'kind' => ['nullable', 'in:peg,logo,output'],
        ], [
            'reference_files.required' => 'Choose at least one file to upload.',
        ]);

        foreach ($request->file('reference_files') as $file) {
            $order->jobOrder->referenceFiles()->create([
                'path' => $file->store('job-order-refs', 'local'),
                'original_name' => $file->getClientOriginalName(),
                'kind' => $data['kind'] ?? null,
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'uploaded_by' => $request->user()->id,
            ]);
        }

        return back()->with('success', ($data['kind'] ?? null) === 'output'
            ? 'Design uploaded — this is what the artist will work from.'
            : 'File uploaded.');
    }

    /** Mark an already-uploaded file as the ChatGPT design the artist works from. */
    public function markReferenceKind(Request $request, \App\Models\JobOrderFile $file): RedirectResponse
    {
        $order = $file->jobOrder->order;
        $this->assertOrderVisible($order);

        $data = $request->validate([
            'kind' => ['required', 'in:peg,logo,output'],
        ]);

        $file->update(['kind' => $data['kind']]);

        return back()->with('success', $data['kind'] === 'output'
            ? 'Set as the design the artist works from.'
            : 'File updated.');
    }

    public function deleteReferenceFile(\App\Models\JobOrderFile $file): RedirectResponse
    {
        $order = $file->jobOrder->order;
        $this->assertOrderVisible($order);

        \Illuminate\Support\Facades\Storage::disk('local')->delete($file->path);
        $file->delete();

        return back()->with('success', 'Reference file removed.');
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
