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

        return redirect()->route('orders.job-order', $order);
    }

    /** Backup manual-create endpoint (job orders are normally auto-created on downpayment). */
    public function storeJobOrder(Request $request, ProductionOrder $order): RedirectResponse
    {
        return $this->createJobOrder($request, $order);
    }

    public function editJobOrder(ProductionOrder $order): View|RedirectResponse
    {
        $this->assertOrderVisible($order);
        $order->load(['jobOrder', 'items', 'client', 'creator', 'tasks.assignee']);
        abort_unless($order->jobOrder, 404);
        if (! $order->mockupApproved()) {
            return redirect()->route('orders.show', $order)
                ->withErrors(['tech_pack' => 'Approve the final mockup before opening the Tech Pack.']);
        }

        return redirect()->route('orders.job-order', $order)
            ->with('success', 'The artist fills the Tech Pack. After mockup approval, it opens to the artist automatically.');
    }

    /** Compatibility endpoint: account officers now review rather than edit. */
    public function updateJobOrder(\Illuminate\Http\Request $request, ProductionOrder $order): \Illuminate\Http\RedirectResponse
    {
        $this->assertOrderVisible($order);
        $order->load(['jobOrder', 'tasks']);
        abort_unless($order->jobOrder, 404);
        if (! $order->mockupApproved()) {
            return redirect()->route('orders.show', $order)
                ->withErrors(['tech_pack' => 'Approve the final mockup before saving the Tech Pack.']);
        }

        // Kept as a compatibility endpoint for old bookmarks/forms. The Tech
        // Pack is no longer split: account officers review it but do not edit
        // the artist's manual fields.
        return redirect()->route('orders.job-order', $order)
            ->withErrors(['tech_pack' => 'The assigned artist fills the complete Tech Pack.']);
    }

    /** The whole job package as ONE document: mockup, template, job order,
     *  production details — one printed page each. */
    /**
     * The optional picture of the export folder shown on the tech pack.
     *
     * Served rather than linked: uploads live on the private disk, so a direct
     * URL to it would not resolve and a public one would hand the shop's folder
     * layout to anyone who guessed the path.
     */
    public function folderShot(ProductionOrder $order)
    {
        $this->assertOrderVisible($order);

        $path = $order->techPack?->folder_shot_path;

        abort_unless($path && \Illuminate\Support\Facades\Storage::disk('local')->exists($path), 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->response(
            $path,
            $order->techPack->folder_shot_name ?: basename($path)
        );
    }

    /** Serve a saved tech-pack picture from private storage. */
    public function techPackImage(ProductionOrder $order, string $slot)
    {
        $this->assertOrderVisible($order);
        abort_unless(in_array($slot, \App\Models\TechPack::IMAGE_SLOTS, true), 404);

        $image = $order->techPack?->image_uploads[$slot] ?? null;
        $path = $image['path'] ?? null;
        abort_unless($path && \Illuminate\Support\Facades\Storage::disk('local')->exists($path), 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->response(
            $path,
            $image['name'] ?? basename($path)
        );
    }

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
            // A garment always needs something to make it from. Saving this
            // page with the list empty produced a job order whose Raw
            // materials step opened with nothing to issue — the supply desk
            // saw a step and no request, and the job quietly waited on a
            // request that was never going to arrive.
            'raw_materials' => ['required', 'array', 'min:1', function ($attr, $value, $fail) {
                // required|array|min:1 still passes on ['', ''] — the form
                // always posts its blank rows.
                if (collect($value)->filter(fn ($v) => filled($v))->isEmpty()) {
                    $fail('List at least one raw material — the supply desk has nothing to issue without it.');
                }
            }],
            'raw_materials.*' => ['nullable', 'string', 'max:255'],
            // How much of each, in the same order as the names. Blank means
            // nobody said, and the desk is not held to a number.
            'raw_material_qty' => ['nullable', 'array'],
            'raw_material_qty.*' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'cutting_type' => ['nullable', 'in:'.implode(',', array_keys(ProductionOrder::CUTTING_TYPES))],
            // Fabric press (required, merges the print onto the fabric) and the
            // decoration — a checkbox toggle; when on it's a press OR embroidery.
            // Step 3 — always needed, it merges the print onto the fabric.
            'fabric_press' => ['required', 'in:'.implode(',', $pressKeys)],
            'decoration_on' => ['nullable', 'boolean'],
            'press' => ['nullable', 'in:'.implode(',', $pressKeys)],
            // Add-ons: which one, what it is when "Others", and what it costs.
            'addon' => ['nullable', 'in:'.implode(',', array_keys(JobOrder::ADDONS))],
            'addon_other' => ['nullable', 'required_if:addon,others', 'string', 'max:255'],
            'addon_note' => ['nullable', 'string', 'max:500'],
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
        // The amounts ride alongside, keyed by material name, so everything
        // that reads rawMaterialsList() still reads a plain list of names.
        $materialNames = [];
        $materialQty = [];
        foreach (($data['raw_materials'] ?? []) as $i => $name) {
            if (blank($name)) {
                continue;
            }

            $materialNames[] = $name;
            $amount = $data['raw_material_qty'][$i] ?? null;

            if (is_numeric($amount) && (float) $amount > 0) {
                $materialQty[$name] = round((float) $amount, 2);
            }
        }

        $order->jobOrder->update([
            'raw_materials' => $materialNames,
            'raw_material_quantities' => $materialQty ?: null,
            'fabric_press' => $fabricPress,
            'press' => $decoPress,
            'addon' => $addon,
            'addon_other' => $addonOther,
            // Kept only while there is an add-on to describe, same as the price.
            'addon_note' => $decoOn ? ($data['addon_note'] ?? null) : null,
            'addon_price' => $addonPrice,
            'needs_embroidery' => $needsEmbroidery,
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

        // Still to send? Then the pack is where they need to be, and the send
        // button is on it — rather than telling them to go and open it again.
        if ($order->jobOrder->status === 'draft') {
            return redirect()->route('orders.job-order', $order)
                ->with('success', $note.' The Tech Pack opens to the artist automatically after mockup approval.');
        }

        return redirect()->route('orders.show', $order)->with('success', $note);
    }

    public function sendJobOrderToArtist(\Illuminate\Http\Request $request, ProductionOrder $order): \Illuminate\Http\RedirectResponse
    {
        $this->assertOrderVisible($order);
        $order->load(['jobOrder', 'tasks']);
        abort_unless($order->jobOrder, 404);

        // A stale page can still post the old Send button after mockup approval
        // has already opened the pack automatically. Treat that as success,
        // not a forbidden action.
        if ($order->jobOrder->status === 'sent_to_artist') {
            return redirect()->route('orders.show', $order)
                ->with('success', 'The Tech Pack is already open to the artist.');
        }

        abort_unless($order->jobOrder->status === 'draft', 403);

        // The Tech Pack is completed only after the client approves the final
        // mockup; the artist needed only the reference during mockup creation.
        if (! $order->mockupApproved()) {
            return back()->withErrors(['job_order' => 'Approve the final mockup before sending the Tech Pack.']);
        }

        if (! $order->hasDownpayment()) {
            return back()->withErrors(['job_order' => 'Record the downpayment before sending the Tech Pack to the artist.']);
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

        // The mockup is already approved. Sending releases the held Tech Pack
        // task to the same artist.
        $order->unlockStage(ProductionOrder::STAGE_MOCKUP);

        // NOTE: material requests are NOT raised here. They're raised when the
        // leader approves the design package, i.e. when the Raw materials stage
        // opens — see ProductionOrder::unlockStage().

        return redirect()->route('orders.show', $order)
            ->with('success', 'Tech Pack sent to the artist. When finished, it will return to the account officer for approval before going to the leader.');
    }
}
