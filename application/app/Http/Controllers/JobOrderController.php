<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOrderAccess;
use App\Models\JobOrder;
use App\Models\ProductionOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Job orders — the production sheet an order becomes after downpayment:
 * filling in specs, sending to the artist, and the production details.
 * Split out of ProductionOrderController.
 */
class JobOrderController extends Controller
{
    use AuthorizesOrderAccess;

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
            // Step 3 — always needed, it merges the print onto the fabric.
            'fabric_press' => ['required', 'in:'.implode(',', $pressKeys)],
            'decoration_on' => ['nullable', 'boolean'],
            'press' => ['nullable', 'in:'.implode(',', $pressKeys)],
            'embroidery_note' => ['nullable', 'string', 'max:500'],
            // Add-ons: which one, what it is when "Others", and what it costs.
            'addon' => ['nullable', 'in:'.implode(',', array_keys(JobOrder::ADDONS))],
            'addon_other' => ['nullable', 'required_if:addon,others', 'string', 'max:255'],
            'addon_price' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
        ], [
            'addon_other.required_if' => 'Say what the add-on is when you choose Others.',
        ]);

        // Add-ons off → no add-on and no add-on press. On → the chosen add-on,
        // whose press is matched automatically (Others has none, so the officer
        // picks it from the press list).
        $fabricPress = $data['fabric_press'];
        $decoOn = (bool) ($data['decoration_on'] ?? false);

        $addon = $decoOn ? ($data['addon'] ?? null) : null;
        $addonOther = ($addon === 'others') ? ($data['addon_other'] ?? null) : null;
        $addonPrice = $decoOn && filled($data['addon_price'] ?? null)
            ? round((float) $data['addon_price'], 2)
            : null;

        // The matched press drives production routing; "Others" falls back to
        // whatever press was picked in the dropdown.
        $decoPress = null;
        if ($decoOn) {
            $decoPress = JobOrder::pressForAddon($addon) ?? ($data['press'] ?? null);
        }
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
            'addon' => $addon,
            'addon_other' => $addonOther,
            'addon_price' => $addonPrice,
            'needs_embroidery' => $needsEmbroidery,
            'embroidery_note' => $needsEmbroidery ? ($data['embroidery_note'] ?? null) : null,
        ]);

        // The add-on is charged to the client, so fold it into the order total —
        // that drives the payment section's balance and the quotation.
        $order->load('jobOrder')->recomputeTotal();

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
}
