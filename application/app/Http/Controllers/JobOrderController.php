<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOrderAccess;
use App\Models\InventoryItem;
use App\Models\JobOrder;
use App\Models\ProductionOrder;
use App\Models\TechPack;
use App\Services\Stations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
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

    public function editJobOrder(Request $request, ProductionOrder $order): View|RedirectResponse
    {
        $this->assertOrderVisible($order);
        $order->load(['jobOrder', 'items', 'client', 'creator', 'tasks.assignee', 'techPacks']);
        abort_unless($order->jobOrder, 404);

        // This used to redirect to the artist's sheet and never render its own
        // view at all, so job-orders/edit.blade.php - the officer's copy, the
        // one carrying the form - was unreachable. The page's own comment had
        // described the officer filling the header the whole time.
        //
        // No mockup gate: the officer fills the header when the job is taken,
        // which is before a mockup exists. The gate protected the artist's half
        // of the sheet, and their half is not offered here.
        // Two sheets, two sets of boxes. The batch sheet is only offered once
        // it has been opened from the approved sample - before that there is
        // one garment and one sheet to fill in.
        return view('job-orders.edit', [
            'order' => $order,
            'jobOrder' => $order->jobOrder,
            'phase' => $this->phaseAsked($request, $order),
        ]);
    }

    /**
     * Which sheet the officer asked for, refusing one that does not exist yet.
     *
     * A link to the batch sheet before the sample has been approved would
     * quietly open a second sheet nobody drew, so an unopened phase falls back
     * to the sample rather than creating anything.
     */
    private function phaseAsked(Request $request, ProductionOrder $order): string
    {
        $asked = (string) $request->query('phase', TechPack::PHASE_SAMPLE);

        return $asked === TechPack::PHASE_MASSPROD
            && $order->techPackFor(TechPack::PHASE_MASSPROD)
                ? TechPack::PHASE_MASSPROD
                : TechPack::PHASE_SAMPLE;
    }

    /** Compatibility endpoint: account officers now review rather than edit. */
    public function updateJobOrder(Request $request, ProductionOrder $order): RedirectResponse
    {
        $this->assertOrderVisible($order);
        $order->load(['jobOrder', 'tasks']);
        abort_unless($order->jobOrder, 404);
        // The header of the sheet belongs to the account officer: who the job
        // is for, what garment, which print and printer, what fabric. The
        // office knows all of it when the job is taken, and the artist was
        // retyping it off the order form - guessing wherever the two disagreed.
        //
        // It is filled when the job is taken, which is BEFORE the mockup is
        // approved, so this no longer waits on that approval. The gate was
        // there to protect the artist's half of the sheet, and the artist's
        // half is not accepted here however the form is posted: only the six
        // header fields below are read, and everything else is dropped.
        abort_unless($request->user()->isSales() || $request->user()->isLeader(), 403);

        $data = $request->validate([
            'design_name' => ['nullable', 'string', 'max:120'],
            'fitting' => ['nullable', 'string', 'max:60'],
            'item_style' => ['nullable', 'string', 'max:100'],
            'print_type' => ['nullable', 'string', 'max:60'],
            'printer' => ['nullable', 'string', Rule::in(array_keys(JobOrder::printerOptions()))],
            'fabric' => ['nullable', 'string', 'max:255'],
            // The rest of the spec. The officer takes all of it from the
            // client; the artist was retyping it under a picture.
            'neck' => ['nullable', 'string', 'max:100'],
            'cuff_arm_sleeves' => ['nullable', 'string', 'max:100'],
            'neck_label' => ['nullable', 'string', 'max:120'],
            'packaging' => ['nullable', 'string', 'max:120'],
            'bottom_hem' => ['nullable', 'string', 'max:255'],
            'free_logo_sticker' => ['nullable', 'string', 'max:120'],
            'tshirt_color' => ['nullable', 'string', 'max:60'],
            'thread_color' => ['nullable', 'string', 'max:60'],
            'zipper_type' => ['nullable', 'string', 'max:60'],
            'lip_pocket_color' => ['nullable', 'string', 'max:60'],
            'placing_title' => ['nullable', 'string', 'max:160'],
            'pack_created_date' => ['nullable', 'date'],
            'pack_delivery_date' => ['nullable', 'date'],
        ]);

        // There may be no pack yet - the officer reaches this sheet before any
        // artist has drawn on it, which is the whole point of the change.
        //
        // Which sheet: the batch one when they are on it, else the sample.
        // Saving the batch sheet never touches the sample - that sheet is the
        // record of what the client held and approved.
        $phase = $this->phaseAsked($request, $order);
        $pack = $order->openTechPack($phase);
        $pack->fill(Arr::only($data, [
            'design_name', 'fitting', 'item_style', 'tshirt_color', 'thread_color',
            'zipper_type', 'lip_pocket_color', 'placing_title',
            'pack_created_date', 'pack_delivery_date',
        ]));
        $pack->production_order_id = $order->id;
        $pack->phase = $phase;
        $pack->save();

        $order->jobOrder->update(
            Arr::only($data, [
                'print_type', 'printer', 'fabric', 'neck', 'cuff_arm_sleeves',
                'neck_label', 'packaging', 'bottom_hem', 'free_logo_sticker',
            ])
        );

        // Naming a sticker on the sheet puts the sticker step on the floor, and
        // clearing the row takes it away again. It moved here with the box:
        // the artist no longer types this row.
        if (array_key_exists('free_logo_sticker', $data)) {
            $order->update([
                'needs_sticker' => ProductionOrder::namesASticker($data['free_logo_sticker']),
            ]);
        }

        // The print type decides the press and the cutting route, and it is
        // the officer who sets it now. Without this the job kept whatever
        // route it was given when it was taken.
        if (array_key_exists('print_type', $data)) {
            $order->applyPrintTypeRouting();
        }

        return redirect()->route('job-orders.edit', ['order' => $order] + ($phase === TechPack::PHASE_MASSPROD ? ['phase' => $phase] : []))
            ->with('success', $pack->phaseLabel().' Tech Pack saved.');
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

        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response(
            $path,
            $order->techPack->folder_shot_name ?: basename($path)
        );
    }

    /**
     * Serve a saved tech-pack picture from private storage.
     *
     * WHICH sheet is asked for by name. An order carries two now, and both
     * have a front_mockup - the sample the client approved and the batch drawn
     * from it - so a request that only names the slot cannot say which picture
     * it means. Read off the sample by default, which is every pack drawn
     * before the split and every link written before this parameter existed.
     */
    public function techPackImage(Request $request, ProductionOrder $order, string $slot)
    {
        $this->assertOrderVisible($order);
        // imageSlots(), not IMAGE_SLOTS: the spare sample boxes and the extra
        // mockups are real slots, and served from the same private disk. Asked
        // against the short list, a picture in one of them 404'd on a sheet
        // that was showing it.
        abort_unless(in_array($slot, TechPack::imageSlots(), true), 404);

        $phase = $request->query('phase') === TechPack::PHASE_MASSPROD
            ? TechPack::PHASE_MASSPROD
            : TechPack::PHASE_SAMPLE;

        $image = $order->techPackFor($phase)?->image_uploads[$slot] ?? null;
        $path = $image['path'] ?? null;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response(
            $path,
            $image['name'] ?? basename($path)
        );
    }

    /** Serve an artist-imported one-image Tech Pack from private storage. */
    public function importedTechPack(Request $request, ProductionOrder $order)
    {
        $this->assertOrderVisible($order);

        $phase = $request->query('phase') === TechPack::PHASE_MASSPROD
            ? TechPack::PHASE_MASSPROD
            : TechPack::PHASE_SAMPLE;
        $pack = $order->techPackFor($phase);
        $path = $pack?->imported_pack_path;

        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response(
            $path,
            $pack->imported_pack_name ?: basename($path)
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
            // The two shelves, so the officer picks the row the desk will
            // actually deduct instead of typing a name for it to guess at.
            // "QA700" typed here is ten QUIANAs for the supervisor to choose
            // between; "QUIANA (140GSM) BLK" picked here is one.
            //
            // With what is on each: an officer promising a client three hundred
            // of something the shelf holds four of should find that out while
            // they are writing the job, not a week later when the desk rejects
            // the request. Short keys because this is eighteen hundred rows on
            // the page and the names are long enough already.
            'shelfMaterials' => InventoryItem::query()
                ->orderBy('name')
                ->get(['name', 'kind', 'quantity', 'unit'])
                ->unique(fn ($item) => $item->kind.'|'.$item->name)
                ->groupBy('kind')
                ->map(fn ($rows) => $rows->map(fn ($item) => [
                    'n' => $item->name,
                    'q' => $item->qtyForHumans(),
                    'u' => (string) $item->unit,
                    // Zero is worth saying differently from "0", which reads
                    // as a number somebody typed rather than an empty shelf.
                    'o' => (float) $item->quantity <= 0,
                ])->values()->all())
                ->all(),
        ]);
    }

    public function updateProductionDetails(Request $request, ProductionOrder $order): RedirectResponse
    {
        $this->assertOrderVisible($order);
        $order->load('jobOrder');
        abort_unless($order->jobOrder, 404);

        // Both press dropdowns accept the real presses OR embroidery.
        $pressKeys = array_keys(JobOrder::pressOptions());

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
            'raw_materials.*' => ['required', 'string', 'max:255'],
            // How much of each, in the same order as the names. Blank means
            // nobody said, and the desk is not held to a number.
            'raw_material_qty' => ['required', 'array'],
            'raw_material_qty.*' => ['required', 'numeric', 'min:0.01', 'max:999999999'],
            // Which shelf each material comes off: the supervisor's fabric or
            // the desk's ready-made stock.
            'raw_material_kind' => ['nullable', 'array'],
            'raw_material_kind.*' => ['nullable', 'in:fabric,ready_made'],
            // Neither the cutting nor the fabric press is asked for any more:
            // the print type decides both. See JobOrder::printRouting().
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

        foreach (array_keys($data['raw_materials']) as $index) {
            if (! isset($data['raw_material_qty'][$index])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'raw_material_qty' => 'Enter a quantity greater than zero for every raw material.',
                ]);
            }
        }

        // Add-ons off → no add-on and no add-on press. On → the chosen add-on,
        // whose press is matched automatically (Others has none, so the officer
        // picks it from the press list).
        // What the print type says this job is cut and pressed by. It was two
        // dropdowns, defaulted from the print type and overridable, so the shop
        // could be told a job was sublimation and cut by hand.
        $routing = $order->jobOrder->printRouting();
        $fabricPress = $routing['fabric_press'];
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
        $materialKind = [];
        foreach (($data['raw_materials'] ?? []) as $i => $name) {
            if (blank($name)) {
                continue;
            }

            $materialNames[] = $name;
            $amount = $data['raw_material_qty'][$i] ?? null;

            if (is_numeric($amount) && (float) $amount > 0) {
                $materialQty[$name] = round((float) $amount, 2);
            }

            // Fabric unless the officer said ready-made. Kept beside the names
            // rather than inside them, so everything reading rawMaterialsList()
            // still reads a plain list of names.
            $materialKind[$name] = ($data['raw_material_kind'][$i] ?? null) === 'ready_made'
                ? InventoryItem::KIND_READY_MADE
                : InventoryItem::KIND_FABRIC;
        }

        $order->jobOrder->update([
            'raw_materials' => $materialNames,
            'raw_material_quantities' => $materialQty ?: null,
            'raw_material_kinds' => $materialKind ?: null,
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
            ->whereIn('status', Stations::RELEASED)
            ->exists();

        if ($rawStepOpen) {
            $order->refresh()->syncMaterialRequests();
        }

        $newCut = $routing['cutting'];
        $note = 'Production details saved.';

        if ($newCut !== $order->cutting_type) {
            if ($order->canEditCutting()) {
                // Only the cutting steps are swapped - see changeCuttingTo().
                // The press and the decoration are left alone, so this works
                // on an order whose press has already run.
                $order->changeCuttingTo($newCut);
                $note .= ' Cutting steps updated.';
            } else {
                // Name what actually stopped it. It used to say cutting had
                // been done whatever the reason, which on an order sitting AT
                // cutting reads as the app being wrong about its own job.
                $started = $order->tasks()
                    ->whereIn('stage', [5, 11])
                    ->whereIn('status', ['in_progress', 'for_checking', 'complete'])
                    ->orderBy('stage')
                    ->first();

                $note .= ' Cutting was NOT changed — '
                    .($started
                        ? strtolower($started->department).' is already '
                            .($started->status === 'complete' ? 'done' : 'under way').' on this order.'
                        : 'cutting has already started on this order.');
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

    public function sendJobOrderToArtist(Request $request, ProductionOrder $order): RedirectResponse
    {
        $this->assertOrderVisible($order);
        $order->load(['jobOrder', 'tasks']);
        abort_unless($order->jobOrder, 404);

        // A stale page can still post the old Send button after mockup approval
        // has already opened the pack automatically. Treat that as success,
        // not a forbidden action.
        if ($order->jobOrder->status === 'sent_to_artist') {
            return redirect()->route('job-orders.production', $order)
                ->with('success', 'The Tech Pack is already open to the artist. Complete the raw materials and quantities below.');
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

        return redirect()->route('job-orders.production', $order)
            ->with('success', 'Tech Pack sent to the artist. Next, complete the required raw materials and quantities below.');
    }
}
