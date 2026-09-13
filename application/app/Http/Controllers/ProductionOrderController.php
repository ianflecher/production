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
            ->with(['tasks', 'client'])
            // Answered per row on the list, so answer it in this query rather
            // than once per order (see hasDownpayment).
            ->withExists('payments')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('order_number', 'like', "%{$search}%")
                ->orWhere('customer_name', 'like', "%{$search}%")))
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            // Finished work is history, and on a busy year it is most of the
            // list. The default view is what is still open; completed orders
            // have their own tab rather than burying the live ones.
            ->when($status === '', fn ($q) => $q->where('status', '!=', 'complete'))
            // Sixty days after it finished, a job leaves the lists — the
            // completed tab included, because on a busy year that tab is most
            // of the list and none of it is anything anybody is doing.
            //
            // It answers to its NUMBER, though: typing IC2026-00042 brings it
            // straight back. Searching a client's name does not, or one long
            // customer would drag five years of finished work up with them.
            ->where(fn ($q) => $q
                ->whereNot(fn ($a) => $a->archived())
                ->when($search !== '', fn ($w) => $w->orWhere('order_number', 'like', "%{$search}%")))
            // Late work first, then what is due today, then everything else.
            // The list is read from the top and the badges are already drawn in
            // red — but a delayed job used to sit wherever its order number put
            // it, which on a full page is below the fold.
            //
            // Bound dates rather than CURDATE(): the tests run on SQLite, which
            // does not have it.
            ->orderByRaw(
                "CASE WHEN status = 'active' AND due_date IS NOT NULL AND due_date < ? THEN 0"
                ." WHEN status = 'active' AND due_date IS NOT NULL AND due_date = ? THEN 1"
                .' ELSE 2 END',
                [now()->startOfDay()->toDateString(), now()->startOfDay()->toDateString()]
            )
            ->orderByRaw($statusOrder)
            ->orderBy('due_date')
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


    public function create(Request $request): View|RedirectResponse
    {
        // Page two. The inquiry holds the client, taken on page one — an order
        // is always reached through one, so nobody who asked goes unrecorded.
        //
        // Arriving here without one means starting in the middle: the way in is
        // page one, so that is where it sends you.
        if (blank($request->query('inquiry'))) {
            return redirect()->route('inquiries.create');
        }

        $inquiry = \App\Models\Inquiry::with(['client', 'designs'])->findOrFail($request->query('inquiry'));

        abort_unless(
            $inquiry->created_by === $request->user()->id
                || $request->user()->isLeader()
                || ($request->user()->leadsTeam() && $inquiry->team === $request->user()->team),
            403
        );

        // A partial approval may already have opened its job order. Keep the
        // remaining designs on the layout board, but never create a second
        // order for the same brief.
        // A brief becomes ONE ORDER PER DESIGN. Five products on one enquiry is
        // ordinary - Stephanie Moto asked for five, 830 pieces - and this used
        // to bounce the officer back to the first order they wrote, so the
        // other four had to be opened as four separate enquiries for the same
        // client, losing the one thing the brief was keeping: that it is a
        // single job for a single person.
        //
        // Which design this order is for comes in the URL. Without one, the
        // first approved design still waiting for an order is assumed - which
        // is what the officer means when there is only one.
        $design = $request->query('design')
            ? $inquiry->designs()->whereKey($request->query('design'))->first()
            : $inquiry->designsAwaitingAnOrder()->first();

        if ($design?->order) {
            return redirect()->route('orders.show', $design->order->id)
                ->with('success', $design->name().' already has a job order.');
        }

        if (! $design && $inquiry->production_order_id) {
            return redirect()->route('orders.show', $inquiry->production_order_id)
                ->with('success', 'Every approved design on this brief already has a job order. The rest are still in Layout.');
        }

        // A layout that went to an artist has to come back approved before the
        // job order opens. Nothing was sent — a walk-in with their own artwork,
        // say — and there is nothing to wait for.
        if ($inquiry->layout_sent_at && ! $inquiry->hasApprovedDesign()) {
            return redirect()->route('inquiries.layout', $inquiry)
                ->with('success', $inquiry->layoutSubmitted()
                    ? 'The layout is back — approve at least one design with the client before writing the job order.'
                    : 'The layout is still with the artist. The job order opens once the client approves at least one design.');
        }

        // The officer sells from their own price list — the merch line is a
        // different list of products at different prices, not a discount on
        // the standard one.
        $list = \App\Services\PricingService::listFor(auth()->user());

        return view('orders.create', [
            // The design being ordered, so the form can say which of the five
            // this one is and carry it through to the order.
            'design' => $design,
            'inquiry' => $inquiry,
            // Listed surname-first so the office can find a client by family name.
            'clients' => Client::bySurname()->get(),
            'decorationMethods' => ProductionOrder::DECORATION_METHODS,
            'cuttingTypes' => ProductionOrder::CUTTING_TYPES,
            'products' => \App\Services\PricingService::products($list),
            'priceList' => $list,
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
    private function capacityError(?string $dueDate, int $qty, ?int $exceptOrderId = null, ?string $productType = null): ?string
    {
        if (blank($dueDate)) {
            return null;
        }

        $cap = \App\Services\PricingService::dailyCapacity($productType);
        if ($cap === null) {
            return null;
        }

        // Per PRODUCT. Five hundred shirts and five hundred riding jerseys are
        // not the same day's work and do not compete for the same bench, so a
        // date full of shirts must not refuse a jersey.
        $booked = ProductionOrder::bookedQtyForDate($dueDate, $exceptOrderId, $productType);

        if ($booked + $qty <= $cap) {
            return null;
        }

        $when = \Illuminate\Support\Carbon::parse($dueDate)->format('M j, Y');
        $left = max(0, $cap - $booked);

        $what = $productType
            ? (\App\Services\PricingService::label($productType) ?? $productType)
            : 'work';

        return "{$when} already has ".number_format($booked).' of '.number_format($cap)
            ." {$what} booked — only ".number_format($left).' left. Pick another due date.';
    }

    public function store(Request $request): RedirectResponse
    {
        // Which price list this officer sells from. It is read here and
        // written onto the order below, so the job keeps these prices even if
        // the officer is later moved to another list.
        $list = \App\Services\PricingService::listFor($request->user());

        $data = $request->validate([
            // Typed by the account officer (their own numbering, e.g. IC2026-00016).
            // Not "unique" any more: a second design on the same job takes
            // the job's existing number on purpose. Whether this one is free
            // is decided below, once the client is known.
            'order_number' => ['required', 'string', 'max:50'],

            // The client was taken on page one and is read off the inquiry, so
            // this page does not ask for them again.
            //
            // An order without one is still accepted, and makes its own: every
            // order has an inquiry behind it because the record of who asked
            // has to exist either way, and a job written straight in would
            // otherwise be a client the follow-up list never knew about.
            'inquiry_id' => ['nullable', 'integer', 'exists:inquiries,id'],
            // Which design of the brief this order is making.
            'inquiry_design_id' => ['nullable', 'integer', 'exists:inquiry_designs,id'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'client_name' => ['required_without_all:inquiry_id,client_id', 'nullable', 'string', 'max:255'],
            'client_last_name' => ['required_without_all:inquiry_id,client_id', 'nullable', 'string', 'max:255'],
            'client_contact' => ['required_without_all:inquiry_id,client_id', 'nullable', 'string', 'max:255'],
            'client_address' => ['required_without_all:inquiry_id,client_id', 'nullable', 'string', 'max:255'],
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
            'withholding_rate' => ['nullable', 'integer', 'in:0,1,2'],
            'discount_amount' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'discount_note' => ['nullable', 'string', 'max:255'],
            'shipping_cost' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'charge_layout_fee' => ['nullable', 'boolean'],
            'downpayment_waived' => ['nullable', 'boolean'],
            'downpayment_waiver_note' => ['nullable', 'string', 'max:500'],

            'product_type' => ['required', 'string', 'in:'.implode(',', [...array_keys(\App\Services\PricingService::products($list)), '__other__'])],
            'product_type_custom' => ['nullable', 'required_if:product_type,__other__', 'string', 'max:100'],
            'back_pocket' => ['nullable', 'boolean'],
            'back_pocket_qty' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'massprod_priority' => ['nullable', 'boolean'],
            'skip_sample' => ['nullable', 'boolean'],
            'unit_price_override' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            // CS and a typed "other size" are off the price list, so they carry
            // their own price per piece rather than the tier price.
            'custom_size_price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            // Rush: the fee is agreed per job, so it must be entered when ticked.
            'rush' => ['nullable', 'boolean'],
            'rush_fee' => ['nullable', 'required_if:rush,1', 'numeric', 'min:0', 'max:10000000'],
        ], [
            'client_last_name.required_without' => "Enter the client's last name.",
            'client_contact.required_without' => 'Enter the contact number.',
            'client_address.required_without' => 'Enter the address.',
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

        // The ceiling is on the ORDER, not on any one size: five hundred split
        // across S to XXL is still five hundred to make.
        // The 500-piece ceiling applies only to the four standard apparel
        // lines. Other/quoted apparel is sized by the officer's quotation.
        $max = \App\Services\PricingService::dailyCapacity($data['product_type'] ?? null);

        if ($max !== null && $sizes->sum() > $max) {
            return back()->withInput()->withErrors(['sizes' => sprintf(
                'That is %s pieces. One order takes at most %s of this product — split it across two orders, or ask a leader.',
                number_format($sizes->sum()),
                number_format($max)
            )]);
        }

        if ($sizes->isEmpty()) {
            return back()->withInput()->withErrors(['sizes' => 'Enter how many pieces for at least one size.']);
        }

        $data['quantity'] = $sizes->sum();

        // Production can only take so many pieces per day.
        if ($msg = $this->capacityError($data['due_date'], $data['quantity'], null, $data['product_type'] ?? null)) {
            return back()->withInput()->withErrors(['due_date' => $msg]);
        }

        // Price per piece: standard tier price (+ back pocket), unless the sales
        // agent overrides it (specials, or over-100 quotations).
        $backPocket = (bool) ($data['back_pocket'] ?? false);
        $backPocketQty = $backPocket ? (int) ($data['back_pocket_qty'] ?? $data['quantity']) : null;
        $quote = \App\Services\PricingService::quote($data['product_type'], $data['quantity'], $backPocket, $backPocketQty, $list);
        // The service normalises/caps the pocket count and its charge.
        $backPocketQty = $backPocket ? $quote['back_pocket_qty'] : null;
        $backPocketAmount = $quote['back_pocket_amount'];

        if (! empty($data['unit_price_override'])) {
            $unitPrice = (float) $data['unit_price_override'];
        } else {
            $unitPrice = $quote['unit']; // null when a quotation is needed (>100)
        }

        $customSizePrice = ($data['custom_size_price'] ?? null) === null || $data['custom_size_price'] === ''
            ? null
            : (float) $data['custom_size_price'];

        // A rush order carries a one-off fee agreed for that job.
        $rush = (bool) ($data['rush'] ?? false);
        $rushFee = $rush ? round((float) ($data['rush_fee'] ?? 0), 2) : null;

        // Total = (unit x qty) + back pocket + rush, less the discount, then
        // +12% VAT and less any selected withholding tax.
        $vat = (bool) ($data['vat_inclusive'] ?? false);
        $withholdingRate = $vat ? (int) ($data['withholding_rate'] ?? 0) : 0;
        $discount = (float) ($data['discount_amount'] ?? 0);
        $shippingCost = round((float) ($data['shipping_cost'] ?? 0), 2);
        $chargeLayoutFee = $request->boolean('charge_layout_fee');
        $totalPrice = ProductionOrder::computeTotal(
            $unitPrice, $data['quantity'], $discount, $vat, $backPocketAmount, (float) $rushFee, $withholdingRate, $shippingCost,
            $chargeLayoutFee
        );

        $clientFields = [
            'contact_number' => $data['client_contact'] ?? null,
            'company' => $data['client_company'] ?? null,
            'office_address' => $data['client_address'] ?? null,
            'delivery_address' => $data['client_address'] ?? null,
            'tin' => $data['client_tin'] ?? null,
        ];

        if (! empty($data['inquiry_id'])) {
            // The client came from the inquiry; page two does not ask again.
            $inquiry = \App\Models\Inquiry::findOrFail($data['inquiry_id']);

            abort_unless(
                $inquiry->created_by === $request->user()->id
                    || $request->user()->isLeader()
                    || ($request->user()->leadsTeam() && $inquiry->team === $request->user()->team),
                403
            );

            if ($inquiry->layout_sent_at && ! $inquiry->hasApprovedDesign()) {
                return back()->withErrors(['inquiry_id' => 'Approve at least one design with the client before creating the job order.']);
            }

            // The design this order is for. Named by the form, else the first
            // approved one still waiting to be written - which is what the
            // officer means when the brief carries only one.
            $orderedDesign = ! empty($data['inquiry_design_id'])
                ? $inquiry->designs()->whereKey($data['inquiry_design_id'])->first()
                : $inquiry->designsAwaitingAnOrder()->first();

            if ($orderedDesign?->order) {
                return back()->withErrors(['inquiry_id' =>
                    $orderedDesign->name().' already has a job order.']);
            }

            $client = $inquiry->client;
        } else {
            $orderedDesign = null;
            $client = ! empty($data['client_id'])
                ? Client::findOrFail($data['client_id'])
                : Client::create($clientFields + [
                    'name' => $data['client_name'],
                    'last_name' => $data['client_last_name'] ?? null,
                    'created_by' => $request->user()->id,
                ]);

            // The inquiry this order should have come from. Written now so the
            // client database holds everyone who ever asked, however the job
            // reached the shop.
            $inquiry = \App\Models\Inquiry::create([
                'client_id' => $client->id,
                'created_by' => $request->user()->id,
                'team' => $request->user()->team,
                'status' => \App\Models\Inquiry::STATUS_OPEN,
            ]);
        }

        // One inquiry, one job order number.
        //
        // A brief carries several designs and each approved design is written
        // up as its own order, because each is its own run of work - its own
        // sizes, its own press, its own steps on the floor. They are still ONE
        // job, so they share the job's number instead of taking one each: that
        // is how one brief's 830 pieces came to sit under IC2026-00005 and
        // IC2026-01174 with nothing saying they belonged together.
        //
        // The BRIEF is what makes them one job, not the client. The same
        // person can have two unrelated enquiries running at once, and those
        // are two jobs.
        //
        // Only while the work is live. A brief whose job is delivered or
        // cancelled is finished with.
        $openJob = ProductionOrder::openJobFor($inquiry->id);

        if ($openJob) {
            // Whatever was typed or suggested is set aside: the job already
            // has a number, and this is another part of that job.
            $orderNumber = $openJob->order_number;
        } else {
            // A NEW job still may not take a number that is already on the
            // books - two briefs under one number is not a job with six parts,
            // it is two jobs nobody can tell apart. Checked here rather than
            // as a validation rule, because the rule above has to be allowed
            // to reuse a number on purpose.
            $orderNumber = trim($data['order_number']);

            if (ProductionOrder::where('order_number', $orderNumber)->exists()) {
                return back()->withInput()->withErrors(['order_number' =>
                    'Job order '.$orderNumber.' is already on another job.']);
            }
        }

        $order = ProductionOrder::createJobOrder([
            'order_number' => $orderNumber,
            'client_id' => $client->id,
            'customer_name' => $client->fullName(),
            'product_type' => $data['product_type'],
            'price_list' => $list,
            'description' => $data['description'] ?? null,
            'quantity' => $data['quantity'],
            'due_date' => $data['due_date'],
            'back_pocket' => $backPocket,
            'back_pocket_qty' => $backPocketQty,
            'massprod_priority' => (bool) ($data['massprod_priority'] ?? false),
            'skip_sample' => (bool) ($data['skip_sample'] ?? false),
            'rush' => $rush,
            'rush_fee' => $rushFee,
            'shipping_cost' => $shippingCost,
            'charge_layout_fee' => $chargeLayoutFee,
            'unit_price' => $unitPrice,
            'custom_size_price' => $customSizePrice,
            'total_price' => $totalPrice,
            'vat_inclusive' => $vat,
            'withholding_rate' => $withholdingRate,
            'discount_amount' => $discount,
            'discount_note' => $data['discount_note'] ?? null,
            'downpayment_waived' => (bool) ($data['downpayment_waived'] ?? false),
            'downpayment_waiver_note' => filled($data['downpayment_waiver_note'] ?? null) ? $data['downpayment_waiver_note'] : null,
            'created_by' => $request->user()->id,
            'status' => 'active',
        ], $data['decoration_methods'] ?? [], $data['cutting_type']);

        foreach ($sizes as $size => $qty) {
            $order->items()->create(['size' => $size, 'quantity' => $qty]);
        }

        // The off-chart pieces are priced separately, so the real total is only
        // knowable once the size breakdown is saved.
        $order->refresh()->recomputeTotal();

        // Design comes first: create the draft job order now so the client
        // reference can be attached right away, then the layout is sent to an
        // artist — no downpayment needed yet.
        $jobOrder = $order->jobOrder()->create([
            'status' => 'draft',
            'created_by' => $request->user()->id,
        ]);

        // The design was collected between Client Details and New Job Order.
        // Move its metadata onto the real job order, then release the layout.
        $jobOrder->update([
            'reference_note' => $inquiry->layout_reference_note,
            'design_brief' => $inquiry->design_brief,
        ]);
        // The brief's own material, then every design drawn under it. A kit
        // approved as six designs arrives on the job order as six drawings -
        // named, so the floor can tell the jersey from the shorts rather than
        // opening six files called layout.png.
        $carry = collect($inquiry->layout_files ?? [])
            ->map(fn ($file) => $file + ['design_name' => null]);

        // This order's own design, when it has one: a jacket order should not
        // arrive carrying the shorts drawings. A brief written as a whole -
        // one design, or an older order with none named - still carries every
        // approved drawing.
        $carryDesigns = $orderedDesign
            ? collect([$orderedDesign])
            : $inquiry->designs->filter(fn ($design) => $design->approved());

        foreach ($carryDesigns as $design) {
            foreach ($design->drawings() as $file) {
                $carry->push($file + ['design_name' => $design->name()]);
            }
        }

        foreach ($carry as $file) {
            $jobOrder->referenceFiles()->create([
                'path' => $file['path'],
                'original_name' => filled($file['design_name'] ?? null)
                    ? $file['design_name'].' - '.$file['original_name']
                    : $file['original_name'],
                'kind' => $file['kind'] ?? 'output',
                'mime' => $file['mime'] ?? null,
                'size' => $file['size'] ?? null,
                'uploaded_by' => $file['uploaded_by'] ?? $request->user()->id,
            ]);
        }
        if (! empty($inquiry->layout_files) || filled($inquiry->layout_reference_note) || $inquiry->designs->isNotEmpty()) {
            // The artist was named back on step 2, and the officer has already
            // been told who it is. Set it before releasing the stage: unlockStage
            // only picks somebody when the task has nobody, so this keeps the
            // promise rather than rolling the rotation a second time.
            if ($inquiry->layout_artist_id) {
                $order->tasks()
                    ->where('stage', ProductionOrder::STAGE_LAYOUT)
                    ->whereNull('assigned_to')
                    ->update(['assigned_to' => $inquiry->layout_artist_id]);
            }

            $order->unlockStage(ProductionOrder::STAGE_LAYOUT);

            // One approved design is enough to begin the sample and
            // pre-production work. Mass production has its own approval gate.
            if ($inquiry->hasApprovedDesign()) {
                $carriedLayout = $order->refresh()->tasks()
                    ->where('stage', ProductionOrder::STAGE_LAYOUT)
                    ->where('status', '!=', 'complete')
                    ->get();

                $carriedLayout->each(fn ($task) => $task->forceFill([
                    'status' => 'complete',
                    'submitted_at' => $inquiry->layout_submitted_at ?? now(),
                    'approved_at' => $inquiry->layout_approved_at ?? now(),
                ])->save());

                $order->forceFill(['layout_approved_at' => $inquiry->layout_approved_at ?? now()])->save();

                // Writing the row is not the same as finishing the step. Going
                // through Task::approve() is what opens the stage after it, and
                // forceFill goes straight to the database - so the layout was
                // done and nothing had been told, leaving the mockup and the
                // tech pack shut.
                //
                // A job with money owing never showed it: Finance confirming
                // the deposit opens stage 2 by its own door. A sponsored job
                // priced at zero has no payment coming to open anything, so
                // IC2026-00006 sat with a finished layout and nothing on any
                // artist's list until somebody noticed.
                //
                // hasDownpayment() already counts "owes nothing" as settled, so
                // the ordinary handler opens the stage the moment it is asked -
                // and a job still waiting on money is left shut, exactly as
                // before.
                if ($finishedLayout = $carriedLayout->last()) {
                    $order->refresh()->handleTaskCompleted($finishedLayout->fresh());
                }
            }
        }

        // Which brief and which design this order came from, so the enquiry can
        // list its orders and each design can say whether it has one yet.
        if ($inquiry) {
            $order->forceFill([
                'inquiry_id' => $inquiry->id,
                'inquiry_design_id' => $orderedDesign?->id,
            ])->save();
        }

        // They asked, and now they have ordered. This is the only way a name
        // comes off the follow-up list — the inquiry keeps the job it became.
        // At least one approved design can open the job order, but the brief
        // must stay open while other designs are still with the artists. That
        // keeps them on the artists' Layout boards instead of making them
        // disappear the moment the first design is approved.
        // Nothing left waiting on an artist or a client is what takes a name
        // off the follow-up list - not "the layout is approved", which is a
        // question a walk-in who never had a layout can never answer yes to.
        // Asked that way, an order written for somebody with their own artwork
        // left them on the follow-up list forever, being chased for a job they
        // had already placed.
        if ($inquiry->designsOutstanding()->isEmpty()
            && $inquiry->fresh()->designsAwaitingAnOrder()->isEmpty()) {
            $inquiry->markOrdered($order);
        } else {
            $inquiry->update(['production_order_id' => $order->id]);
        }

        // Everything said while the layout was being drawn becomes this order's
        // thread. The conversation was already about this job; it only lacked
        // the job to be filed under.
        \App\Models\Message::carryLayoutThreadTo($inquiry, $order);

        return redirect()
            ->route('orders.show', $order)
            ->with('success', "Order {$order->order_number} created for {$client->fullName()} ({$order->quantity} pcs) and sent to the artist for layout.");
    }

    public function edit(ProductionOrder $order): View
    {
        $this->assertOrderVisible($order);
        abort_unless(in_array($order->status, ['active', 'on_hold'], true), 403);

        $order->load('client');

        // If the stored price isn't the standard tier price, it was a custom
        // override — pre-open that field so the edit form preserves it.
        // The list this job was quoted from — a leader opening somebody's
        // merch order must see the merch products, not the standard ones.
        $list = \App\Services\PricingService::resolve($order->price_list);

        $std = \App\Services\PricingService::quote($order->product_type ?? '', $order->quantity, (bool) $order->back_pocket, null, $list);
        $priceOverride = null;
        if ($order->unit_price !== null && ($std['unit'] === null || (float) $order->unit_price !== (float) $std['unit'])) {
            $priceOverride = (float) $order->unit_price;
        }

        return view('orders.edit', [
            'order' => $order,
            'priceOverride' => $priceOverride,
            'decorationMethods' => ProductionOrder::DECORATION_METHODS,
            'cuttingTypes' => ProductionOrder::CUTTING_TYPES,
            'products' => \App\Services\PricingService::products($list),
            'priceList' => $list,
            'backPocketFee' => \App\Services\PricingService::backPocketFee(),
        ]);
    }

    public function update(Request $request, ProductionOrder $order): RedirectResponse
    {
        $hadDownpaymentClearance = $order->hasDownpayment();

        // An edit re-prices against the list the job was created on, never the
        // list of whoever happens to be editing it.
        $list = \App\Services\PricingService::resolve($order->price_list);

        $this->assertOrderVisible($order);
        abort_unless(in_array($order->status, ['active', 'on_hold'], true), 403);

        $data = $request->validate([
            // Correctable now. Still unique — two jobs sharing a number is the
            // fault that made this read-only in the first place, and that part
            // is the database's job rather than the officer's memory.
            //
            // "sometimes", not "required": this form sends it, but the other
            // things that save an order do not, and requiring it turned every
            // one of those into a silent validation failure.
            // Free, or already this job's own number. The parts of one job
            // share a number on purpose, so a plain unique rule would refuse
            // an officer retyping the number their own siblings carry.
            //
            // An order with no brief behind it has no siblings to share with,
            // so it falls back to plain uniqueness rather than excluding a
            // NULL, which in SQL matches nothing and would refuse everything.
            'order_number' => [
                'sometimes', 'required', 'string', 'max:50',
                \Illuminate\Validation\Rule::unique('production_orders', 'order_number')
                    ->ignore($order->id)
                    ->where(fn ($q) => $order->inquiry_id
                        ? $q->where(fn ($w) => $w
                            ->whereNull('inquiry_id')
                            ->orWhere('inquiry_id', '!=', $order->inquiry_id))
                        : $q),
            ],

            'client_name' => ['required', 'string', 'max:255'],
            'client_last_name' => ['required', 'string', 'max:255'],
            'client_contact' => ['required', 'string', 'max:255'],
            'client_company' => ['nullable', 'string', 'max:255'],
            'client_address' => ['required', 'string', 'max:255'],
            'client_tin' => ['nullable', 'string', 'max:50'],

            'description' => ['nullable', 'string', 'max:1000'],
            'due_date' => ['required', 'date'],

            // How many of each size (the inquiry breakdown). Total = quantity.
            'sizes' => ['required', 'array'],
            'sizes.*' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'other_size' => ['nullable', 'string', 'max:50'],
            'other_size_qty' => ['nullable', 'integer', 'min:0', 'max:100000'],

            'vat_inclusive' => ['nullable', 'boolean'],
            'withholding_rate' => ['nullable', 'integer', 'in:0,1,2'],
            'discount_amount' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'discount_note' => ['nullable', 'string', 'max:255'],
            'shipping_cost' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'charge_layout_fee' => ['nullable', 'boolean'],
            'downpayment_waived' => ['nullable', 'boolean'],
            'downpayment_waiver_note' => ['nullable', 'string', 'max:500'],

            'product_type' => ['required', 'string', 'in:'.implode(',', [...array_keys(\App\Services\PricingService::products($list)), '__other__'])],
            'product_type_custom' => ['nullable', 'required_if:product_type,__other__', 'string', 'max:100'],
            'back_pocket' => ['nullable', 'boolean'],
            'back_pocket_qty' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'massprod_priority' => ['nullable', 'boolean'],
            'skip_sample' => ['nullable', 'boolean'],
            'unit_price_override' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            // CS and a typed "other size" are off the price list, so they carry
            // their own price per piece rather than the tier price.
            'custom_size_price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'rush' => ['nullable', 'boolean'],
            'rush_fee' => ['nullable', 'required_if:rush,1', 'numeric', 'min:0', 'max:10000000'],
        ], [
            'client_last_name.required' => "Enter the client's last name.",
            'client_contact.required' => 'Enter the contact number.',
            'client_address.required' => 'Enter the address.',
            'rush_fee.required_if' => 'Enter the rush fee, or untick Rush order.',
        ]);

        if ($data['product_type'] === '__other__') {
            $data['product_type'] = \Illuminate\Support\Str::title(trim($data['product_type_custom']));
        }

        $sizes = $this->collectSizes($data);

        // The ceiling is on the ORDER, not on any one size: five hundred split
        // across S to XXL is still five hundred to make.
        // Other/quoted apparel has no automatic quantity ceiling.
        $max = \App\Services\PricingService::dailyCapacity($data['product_type'] ?? null);

        if ($max !== null && $sizes->sum() > $max) {
            return back()->withInput()->withErrors(['sizes' => sprintf(
                'That is %s pieces. One order takes at most %s of this product — split it across two orders, or ask a leader.',
                number_format($sizes->sum()),
                number_format($max)
            )]);
        }

        if ($sizes->isEmpty()) {
            return back()->withInput()->withErrors(['sizes' => 'Enter how many pieces for at least one size.']);
        }

        $data['quantity'] = $sizes->sum();

        // This order's own pieces don't count against its due date.
        if ($msg = $this->capacityError($data['due_date'], $data['quantity'], $order->id, $data['product_type'] ?? null)) {
            return back()->withInput()->withErrors(['due_date' => $msg]);
        }

        $backPocket = (bool) ($data['back_pocket'] ?? false);
        $backPocketQty = $backPocket ? (int) ($data['back_pocket_qty'] ?? $data['quantity']) : null;
        $quote = \App\Services\PricingService::quote($data['product_type'], $data['quantity'], $backPocket, $backPocketQty, $list);
        $backPocketQty = $backPocket ? $quote['back_pocket_qty'] : null;
        $backPocketAmount = $quote['back_pocket_amount'];
        $unitPrice = ! empty($data['unit_price_override']) ? (float) $data['unit_price_override'] : $quote['unit'];
        $customSizePrice = ($data['custom_size_price'] ?? null) === null || $data['custom_size_price'] === ''
            ? null
            : (float) $data['custom_size_price'];
        $vat = (bool) ($data['vat_inclusive'] ?? false);
        $withholdingRate = $vat ? (int) ($data['withholding_rate'] ?? 0) : 0;
        $discount = (float) ($data['discount_amount'] ?? 0);
        $shippingCost = round((float) ($data['shipping_cost'] ?? 0), 2);
        $chargeLayoutFee = $request->boolean('charge_layout_fee');
        $rush = (bool) ($data['rush'] ?? false);
        $rushFee = $rush ? round((float) ($data['rush_fee'] ?? 0), 2) : null;
        $totalPrice = ProductionOrder::computeTotal(
            $unitPrice, $data['quantity'], $discount, $vat, $backPocketAmount, (float) $rushFee, $withholdingRate, $shippingCost,
            $chargeLayoutFee
        );

        // Keep the linked client's details fixed up too.
        $order->client?->update([
            'name' => $data['client_name'],
            'last_name' => $data['client_last_name'],
            'contact_number' => $data['client_contact'] ?? null,
            'company' => $data['client_company'] ?? null,
            'office_address' => $data['client_address'] ?? null,
            'delivery_address' => $data['client_address'] ?? null,
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
            // Left alone when the form did not send one.
            'order_number' => filled($data['order_number'] ?? null)
                ? trim($data['order_number'])
                : $order->order_number,
            'customer_name' => trim($data['client_name'].' '.$data['client_last_name']),
            'product_type' => $data['product_type'],
            'quantity' => $data['quantity'],
            'due_date' => $data['due_date'],
            'back_pocket' => $backPocket,
            'back_pocket_qty' => $backPocketQty,
            'unit_price' => $unitPrice,
            'custom_size_price' => $customSizePrice,
            'total_price' => $totalPrice,
            'vat_inclusive' => $vat,
            'withholding_rate' => $withholdingRate,
            'discount_amount' => $discount,
            'discount_note' => $data['discount_note'] ?? null,
            'downpayment_waived' => (bool) ($data['downpayment_waived'] ?? false),
            'downpayment_waiver_note' => filled($data['downpayment_waiver_note'] ?? null) ? $data['downpayment_waiver_note'] : null,
            'rush' => $rush,
            'rush_fee' => $rushFee,
            'shipping_cost' => $shippingCost,
            'charge_layout_fee' => $chargeLayoutFee,
        ]);

        // The size mix may have changed, and the off-chart pieces are priced on
        // their own — so the total is settled from the saved breakdown.
        $order->refresh()->recomputeTotal();

        // A waiver may be selected after the client already approved the
        // layout. Payments normally release the final mockup through Finance;
        // the waiver has no payment to confirm, so release that same next stage
        // here instead.
        $order->refresh();
        if (! $hadDownpaymentClearance && $order->hasDownpayment() && $order->layoutApproved()) {
            $order->unlockStage(ProductionOrder::STAGE_MOCKUP);
            $order->scheduleStepDeadlines();
            $order->applySampleDueDate();
            $routingNote = ' Downpayment waived; the artist can now prepare the final mockup.';
        }

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

        $product = $request->query('product_type');
        $cap = \App\Services\PricingService::dailyCapacity($product);

        if (blank($date) || ! strtotime($date)) {
            return response()->json(['booked' => 0, 'capacity' => $cap, 'remaining' => $cap, 'product' => null]);
        }

        $booked = ProductionOrder::bookedQtyForDate($date, $request->integer('except') ?: null, $product);

        return response()->json([
            'booked' => $booked,
            'capacity' => $cap,
            'remaining' => $cap === null ? null : max(0, $cap - $booked),
            // So the hint can say 216 of 500 WHAT.
            'product' => $product ? (\App\Services\PricingService::label($product) ?? $product) : null,
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

    /**
     * Set the deadline for one pipeline step without re-spacing every other
     * step. The automatic schedule gives the first plan; the people running
     * the job need to adjust an individual bench when reality changes.
     */
    public function updateTaskDeadline(Request $request, ProductionOrder $order, Task $task): RedirectResponse
    {
        $this->assertOrderVisible($order);
        abort_unless($task->production_order_id === $order->id, 404);

        // Supervisors resolve as leaders (User::isLeader()), while account
        // officers may edit deadlines on the orders they own.
        abort_unless($request->user()->isLeader() || $request->user()->canCreateOrders(), 403);

        $data = $request->validate([
            'due_at' => ['nullable', 'date'],
        ]);

        $dueAt = filled($data['due_at'] ?? null)
            ? \Illuminate\Support\Carbon::parse($data['due_at'])->endOfDay()
            : null;

        $task->update(['due_at' => $dueAt]);

        return back()->with('success', $task->department.' deadline updated.');
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

    /**
     * Remember where the design was dragged to on the job order sheet.
     *
     * It sits over the description column and can cover the very lines the
     * floor needs to read — and the sheet prints where it was left. Storing it
     * on the order rather than in the browser means the person who moved it and
     * the person who prints it are looking at the same sheet.
     */
    public function saveMockupOffset(Request $request, ProductionOrder $order): \Illuminate\Http\JsonResponse
    {
        $this->assertOrderVisible($order);

        // Bounded: a bad value should not be able to fling the design off the
        // page for everybody with no way back short of editing the database.
        $data = $request->validate([
            'x' => ['required', 'integer', 'between:-2000,2000'],
            'y' => ['required', 'integer', 'between:-2000,2000'],
        ]);

        $order->update([
            'mockup_offset_x' => $data['x'],
            'mockup_offset_y' => $data['y'],
        ]);

        return response()->json(['saved' => true]);
    }

    /**
     * Make the pieces again: a remake of an order that went wrong.
     *
     * A wrong colour, a damaged panel, a seam that failed QC. The remake is a
     * real job — it prints, cuts, sews and gets checked like any other — so it
     * is a new order running the same pipeline, not a note on the old one.
     *
     * It carries no price. The shop is doing the work twice and being paid
     * once, and pretending otherwise would make the month look better than it
     * was. It is pointed at the order it replaces so both are answerable.
     */
    public function storeReplacement(Request $request, ProductionOrder $order): RedirectResponse
    {
        $this->assertOrderVisible($order);

        $data = $request->validate([
            'replacement_reason' => ['required', 'string', 'min:5', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.max(1, (int) $order->quantity)],
            'due_date' => ['required', 'date'],
        ], [
            'replacement_reason.required' => 'Say what went wrong — it is the whole point of recording a remake.',
            'quantity.max' => 'A remake cannot be for more pieces than the original order.',
        ]);

        $replacement = DB::transaction(function () use ($order, $data, $request) {
            $new = ProductionOrder::create([
                'order_number' => $order->order_number.'-R'.($order->replacements()->count() + 1),
                'client_id' => $order->client_id,
                'customer_name' => $order->customer_name,
                'product_type' => $order->product_type,
                'description' => $order->description,
                'decoration_methods' => $order->decoration_methods,
                'cutting_type' => $order->cutting_type,
                'needs_sticker' => $order->needs_sticker,
                'back_pocket' => $order->back_pocket,
                // No first sample. The client approved this garment already and
                // is waiting on pieces they have paid for — showing them one
                // again, and splitting the run into sample + mass production,
                // would hold up the remake for no decision anybody still has to
                // make.
                'skip_sample' => true,
                'back_pocket_qty' => min((int) ($order->back_pocket_qty ?? 0), (int) $data['quantity']),
                'quantity' => $data['quantity'],
                // No charge: this is work being done a second time.
                'unit_price' => 0,
                'total_price' => 0,
                'due_date' => $data['due_date'],
                'status' => 'active',
                'created_by' => $request->user()->id,
                'replaces_order_id' => $order->id,
                'replacement_reason' => $data['replacement_reason'],
            ]);

            // Same sizes, scaled down to what is actually being remade — the
            // biggest sizes first, because a remake is usually the pieces that
            // failed rather than a slice of the whole run.
            $left = (int) $data['quantity'];
            foreach ($order->items()->orderByDesc('quantity')->get() as $item) {
                if ($left <= 0) {
                    break;
                }
                $take = min($left, (int) $item->quantity);
                $new->items()->create(['size' => $item->size, 'quantity' => $take, 'description' => $item->description]);
                $left -= $take;
            }

            // The specs are the same garment, so the sheet starts from the
            // original rather than being typed out again.
            if ($order->jobOrder) {
                // A copy of the sheet, minus everything that belonged to the
                // first run: who sewed it, with what thread, what the checker
                // found. Those are answered again by whoever makes it this time.
                // LEGACY_SEWING_FIELDS too: a job sewn before the record became
                // a log has its names in the old seam columns, and those belong
                // to the run that filled them just as much.
                $floorOwned = array_merge(
                    \App\Models\JobOrder::SEWING_STATION_FIELDS,
                    \App\Models\JobOrder::LEGACY_SEWING_FIELDS,
                    \App\Models\JobOrder::QC_STATION_FIELDS,
                );

                $sheet = $order->jobOrder->replicate(array_merge(
                    ['production_order_id', 'created_by', 'sent_to_artist_by', 'sent_to_artist_at'],
                    $floorOwned,
                ));

                foreach ($floorOwned as $ownedByTheFloor) {
                    $sheet->$ownedByTheFloor = null;
                }

                $sheet->production_order_id = $new->id;
                $sheet->status = 'sent_to_artist';
                $sheet->created_by = $request->user()->id;
                $sheet->save();
            }

            $new->refresh()->rebuildPipeline($new->decoration_methods ?? [], $new->cutting_type);

            // A remake is production only: printer through inventory.
            //
            // The design is already drawn, approved and exported — the artist
            // has nothing to do again, and there is no client sample or second
            // release, because the client is waiting on pieces they have
            // already bought. Building the whole pipeline and asking somebody
            // to click through the design steps would be make-work that also
            // makes the remake look like a fresh sale on every board.
            $new->trimToProductionRun();

            return $new;
        });

        return redirect()->route('orders.show', $replacement)->with('success',
            'Remake '.$replacement->order_number.' created for '.$order->order_number
            .'. It runs the same pipeline and carries no charge.');
    }

    /** Display the mockup image in a centered, focused view. */
    /**
     * The mockup page is now the tech pack.
     *
     * It used to be a picture on its own, which meant making a shirt took two
     * open tabs: the artwork here and the spec on the job order sheet. The tech
     * pack carries both, so this redirects rather than 404s — every existing
     * link, button and bookmark keeps working.
     */
    public function mockup(ProductionOrder $order): RedirectResponse
    {
        $this->assertOrderVisible($order);

        return redirect()->route('orders.job-order', $order);
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
