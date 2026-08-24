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

    public function editJobOrder(ProductionOrder $order): View|RedirectResponse
    {
        $this->assertOrderVisible($order);
        $order->load(['jobOrder', 'items', 'client', 'creator', 'tasks.assignee']);
        abort_unless($order->jobOrder, 404);
        if (! $order->mockupApproved()) {
            return redirect()->route('orders.show', $order)
                ->withErrors(['tech_pack' => 'Approve the final mockup before opening the Tech Pack.']);
        }

        return view('job-orders.edit', [
            'order' => $order,
            'jobOrder' => $order->jobOrder,
            'suggest' => JobOrder::fieldSuggestions(),
        ]);
    }

    /** The account officer's half of the tech pack, posted from the pack itself. */
    public function updateJobOrder(\Illuminate\Http\Request $request, ProductionOrder $order): \Illuminate\Http\RedirectResponse
    {
        $this->assertOrderVisible($order);
        $order->load(['jobOrder', 'tasks']);
        abort_unless($order->jobOrder, 404);
        if (! $order->mockupApproved()) {
            return redirect()->route('orders.show', $order)
                ->withErrors(['tech_pack' => 'Approve the final mockup before saving the Tech Pack.']);
        }

        $data = $request->validate([
            // Header
            'fb_viber_gc' => ['nullable', 'string', 'max:255'],
            // Production (yellow) — print type is free text (with suggestions).
            'print_type' => ['required', 'string', 'max:255'],
            'printer' => ['required', 'in:'.implode(',', array_keys(JobOrder::PRINTERS))],
            'fabric' => ['required', 'string', 'max:255'],
            'free_logo_sticker' => ['nullable', 'string', 'max:255'],
            // Sewing — only the SPEC. Who sewed each seam, with what thread, and what
            // the checker found are recorded at the station by the person doing
            // the work — see JobOrder::SEWING_STATION_FIELDS.
            ...array_fill_keys([
                'neck',
                'cuff_arm_sleeves',
                'neck_label',
                'bottom_hem',
                'extra_seam_label',
            ], ['nullable', 'string', 'max:255']),
            // Quality check (yellow)
            'packaging' => ['nullable', 'string', 'max:255'],
            // Agent notes
            'special_instructions' => ['nullable', 'string', 'max:5000'],
            // Per-line description: one per size row (keyed by order item id).
            'item_desc' => ['nullable', 'array'],
            'item_desc.*' => ['nullable', 'string', 'max:255'],

            // The officer fills these on the TECH PACK itself now, so they are
            // posted from the same sheet and saved onto the pack.
            'design_name' => ['nullable', 'string', 'max:120'],
            'fitting' => ['nullable', 'string', 'max:60'],
            'item_style' => ['nullable', 'string', 'max:100'],
            'quality' => ['nullable', 'string', 'max:60'],
            'tshirt_color' => ['nullable', 'string', 'max:60'],
            'size_range' => ['nullable', 'string', 'max:60'],
            'label_type' => ['nullable', 'in:print_label,neck_label'],
            'color_type' => ['nullable', 'in:tshirt_color,thread_color'],
            'zipper_type' => ['nullable', 'string', 'max:60'],
            'lip_pocket_color' => ['nullable', 'string', 'max:60'],
            'color_1' => ['nullable', 'string', 'max:40'],
            'color_2' => ['nullable', 'string', 'max:40'],
            'color_3' => ['nullable', 'string', 'max:40'],
            'placing_title' => ['nullable', 'string', 'max:160'],
            'front_print_placement' => ['nullable', 'string', 'max:60'],
            'front_actual_size' => ['nullable', 'string', 'max:60'],
            'back_print_placement' => ['nullable', 'string', 'max:60'],
            'back_actual_size' => ['nullable', 'string', 'max:60'],
            'stitch_thread' => ['nullable', 'string', 'max:60'],
            'cutting_method' => ['nullable', 'string', 'max:60'],
            'tag_1_details' => ['nullable', 'string', 'max:120'],
            'tag_2_details' => ['nullable', 'string', 'max:120'],
            'file_location_notes' => ['nullable', 'string', 'max:200'],
            'artist_name' => ['nullable', 'string', 'max:100'],
            'additional_tech_notes' => ['nullable', 'string', 'max:500'],

            // What the job is made of, and how much of each. Asked for on the
            // pack now: the production-details page it used to live on is not
            // in the officer's way any more, and a job with no materials list
            // raises no request — the desk issues nothing and nobody is told.
            'raw_materials' => ['nullable', 'array'],
            'raw_materials.*' => ['nullable', 'string', 'max:255'],
            'raw_material_qty' => ['nullable', 'array'],
            'raw_material_qty.*' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ]);

        // The pack's own fields go to the pack; everything else stays on the
        // job order, so no answer ends up with two homes.
        $packFields = collect($data)->only([
            'design_name', 'fitting', 'item_style', 'quality', 'tshirt_color',
            'size_range', 'label_type', 'color_type', 'zipper_type', 'lip_pocket_color',
            'color_1', 'color_2', 'color_3', 'additional_tech_notes',
            'placing_title', 'front_print_placement', 'front_actual_size',
            'back_print_placement', 'back_actual_size', 'stitch_thread',
            'cutting_method', 'tag_1_details', 'tag_2_details',
            'file_location_notes', 'artist_name',
        ])->all();

        // Was this a fix requested by the leader? Clearing the note sends the
        // (already-made) package straight back to the leader's queue.
        $wasLeaderFix = filled($order->jobOrder->leader_note);

        $order->jobOrder->update(
            collect($data)->except(array_merge(['item_desc'], array_keys($packFields)))->all()
            + ['leader_note' => null]
        );

        $pack = $order->techPack()->firstOrNew([]);
        $pack->fill($packFields);
        $order->techPack()->save($pack);

        // The materials list, with the amount beside each name. Only touched
        // when the form actually carried it, so a save from a page without
        // those boxes does not wipe what is already there.
        if ($request->has('raw_materials')) {
            $names = [];
            $amounts = [];

            foreach ($data['raw_materials'] as $i => $name) {
                if (blank($name)) {
                    continue;
                }

                $names[] = $name;
                $amount = $data['raw_material_qty'][$i] ?? null;

                if (is_numeric($amount) && (float) $amount > 0) {
                    $amounts[$name] = round((float) $amount, 2);
                }
            }

            $order->jobOrder->update([
                'raw_materials' => $names,
                'raw_material_quantities' => $amounts ?: null,
            ]);

            // A list that changed changes what the desk is asked for.
            $order->refresh()->syncMaterialRequests();
        }

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
                ->with('success', 'Tech pack corrected — sent back to the leader for checking.');
        }

        return redirect()->route('job-orders.production', $order)
            ->with('success', 'Tech pack saved. Now the production details — press, cutting and raw materials.');
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

        // Only prompt to send when it hasn't been sent yet.
        if ($order->jobOrder->status === 'draft') {
            $note .= ' Open the Tech Pack to send it to the artist.';
        }

        return redirect()->route('orders.show', $order)->with('success', $note);
    }

    public function sendJobOrderToArtist(\Illuminate\Http\Request $request, ProductionOrder $order): \Illuminate\Http\RedirectResponse
    {
        $this->assertOrderVisible($order);
        $order->load(['jobOrder', 'tasks']);
        abort_unless($order->jobOrder, 404);
        abort_unless($order->jobOrder->status === 'draft', 403);

        // The Tech Pack is completed only after the client approves the final
        // mockup; the artist needed only the reference during mockup creation.
        if (! $order->mockupApproved()) {
            return back()->withErrors(['job_order' => 'Approve the final mockup before sending the Tech Pack.']);
        }

        if (! $order->hasDownpayment()) {
            return back()->withErrors(['job_order' => 'Record the downpayment before sending the Tech Pack to the artist.']);
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

        // The mockup is already approved. Sending releases the held Tech Pack
        // task to the same artist.
        $order->unlockStage(ProductionOrder::STAGE_MOCKUP);

        // NOTE: material requests are NOT raised here. They're raised when the
        // leader approves the design package, i.e. when the Raw materials stage
        // opens — see ProductionOrder::unlockStage().

        // Straight to production details so raw materials & cutting always get
        // filled in — it's the next required step, not an optional side trip.
        return redirect()->route('job-orders.production', $order)
            ->with('success', 'Tech pack sent to the artist. Now fill in the production details (raw materials & cutting) to finish.');
    }
}
