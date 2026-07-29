<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\MaterialRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class InventoryController extends Controller
{
    private function assertAccess(): void
    {
        abort_unless(auth()->user()->canManageInventory(), 403);
    }

    /**
     * The raw-materials desk works this stage from here, not the station board.
     * Once every material this order asked for has been issued or rejected, the
     * "Raw materials" step is finished and the order moves on.
     */
    private function closeRawMaterialsStep(?\App\Models\ProductionOrder $order): void
    {
        if (! $order) {
            return;
        }

        $stillPending = MaterialRequest::where('production_order_id', $order->id)
            ->where('status', 'pending')->exists();

        if ($stillPending) {
            return;
        }

        $task = $order->tasks()
            ->where('department', 'Raw materials')
            ->whereNotIn('status', ['complete', 'cancelled'])
            ->first();

        $task?->forceComplete();
    }

    /* ==================== Stock ==================== */

    public function index(): View
    {
        $this->assertAccess();

        return view('inventory.index', [
            'items' => InventoryItem::orderBy('name')->get(),
            'pendingCount' => MaterialRequest::where('status', 'pending')->count(),
        ]);
    }

    /** Who added stock and who took it out, newest first. */
    public function history(Request $request): View
    {
        $this->assertAccess();

        $movements = \App\Models\StockMovement::with(['item', 'user', 'order'])
            ->when($request->integer('item'), fn ($q, $id) => $q->where('inventory_item_id', $id))
            ->when($request->query('direction'), fn ($q, $d) => $q->where('direction', $d))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('inventory.history', [
            'movements' => $movements,
            'items' => InventoryItem::orderBy('name')->get(['id', 'name']),
            'itemId' => $request->integer('item'),
            'direction' => $request->query('direction'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->assertAccess();

        $data = $request->validate([
            // Ignore soft-deleted rows — a previously removed material can be
            // re-added (it's restored below rather than erroring on the name).
            'name' => ['required', 'string', 'max:255', Rule::unique('inventory_items', 'name')->whereNull('deleted_at')],
            'category' => ['required', 'in:'.implode(',', array_keys(InventoryItem::CATEGORIES))],
            'code' => ['nullable', 'string', 'max:60'],
            'size' => ['nullable', 'string', 'max:60'],
            'color' => ['nullable', 'string', 'max:60'],
            'photo' => ['nullable', 'image', 'max:8192'],
            'unit' => ['required', 'string', 'max:30'],
            'quantity' => ['required', 'numeric', 'min:0', 'max:999999999'],
            // Accounts are shared, so the person bringing it in types their name.
            'operator_name' => ['required', 'string', 'max:100'],
        ], ['operator_name.required' => 'Enter the name of the person adding the stock.']);

        $photoPath = $request->hasFile('photo')
            ? $request->file('photo')->store('inventory-photos', 'public')
            : null;

        $fields = [
            'name' => $data['name'],
            'category' => $data['category'],
            'code' => $data['code'] ?? null,
            'size' => $data['size'] ?? null,
            'color' => $data['color'] ?? null,
            'photo' => $photoPath,
            'unit' => $data['unit'],
            'quantity' => 0,
            // The opening stock is also the "beginning stock" baseline.
            'beginning_stock' => (float) $data['quantity'],
        ];

        // Re-adding a previously removed material restores that row.
        $trashed = InventoryItem::onlyTrashed()->where('name', $data['name'])->first();
        if ($trashed) {
            $trashed->restore();
            $trashed->update($fields);
            $item = $trashed;
        } else {
            $item = InventoryItem::create($fields);
        }

        // Log the opening stock so the history starts with who added it.
        $item->recordMovement((float) $data['quantity'], 'added', 'New item added to inventory', null, $data['operator_name']);

        return back()->with('success', "{$data['name']} added to inventory.");
    }

    public function update(Request $request, InventoryItem $item): RedirectResponse
    {
        $this->assertAccess();

        $data = $request->validate([
            'quantity' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'unit' => ['required', 'string', 'max:30'],
            'note' => ['nullable', 'string', 'max:255'],
            // Who is putting it in / taking it out.
            'operator_name' => ['required', 'string', 'max:100'],
        ], ['operator_name.required' => 'Enter the name of the person moving the stock.']);

        // Log the difference as stock in/out so the change is attributable.
        $delta = (float) $data['quantity'] - (float) $item->quantity;
        $item->update(['unit' => $data['unit']]);
        $item->recordMovement($delta, $delta > 0 ? 'restock' : 'correction', $data['note'] ?? null, null, $data['operator_name']);

        return back()->with('success', "{$item->name} updated — stock is now {$item->fresh()->qtyForHumans()} {$item->unit}.");
    }

    public function destroy(InventoryItem $item): RedirectResponse
    {
        $this->assertAccess();

        $item->delete();

        return back()->with('success', "{$item->name} removed from inventory.");
    }

    /* ==================== Excel (CSV) ==================== */

    public function export()
    {
        $this->assertAccess();

        $items = InventoryItem::orderBy('name')->get();

        return response()->streamDownload(function () use ($items) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads it cleanly
            fputcsv($out, ['name', 'unit', 'quantity']);
            foreach ($items as $i) {
                fputcsv($out, [$i->name, $i->unit, (float) $i->quantity]);
            }
            fclose($out);
        }, 'inventory-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function import(Request $request): RedirectResponse
    {
        $this->assertAccess();

        $request->validate(
            ['file' => ['required', 'file', 'mimes:csv,txt', 'max:10240']],
            ['file.mimes' => 'Upload a CSV file (in Excel: File → Save As → CSV).']
        );

        $rows = array_map('str_getcsv', file($request->file('file')->getRealPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        if ($rows === []) {
            return back()->withErrors(['file' => 'The file is empty.']);
        }

        // Strip BOM and drop the header row if present.
        $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $rows[0][0]);
        if (strtolower(trim($rows[0][0])) === 'name') {
            array_shift($rows);
        }

        $imported = 0;
        $skipped = 0;
        DB::transaction(function () use ($rows, &$imported, &$skipped) {
            foreach ($rows as $row) {
                $name = trim((string) ($row[0] ?? ''));
                $unit = trim((string) ($row[1] ?? '')) ?: 'pcs';
                $qty = $row[2] ?? null;
                if ($name === '' || ! is_numeric($qty)) {
                    $skipped++;

                    continue;
                }
                $item = InventoryItem::firstOrCreate(['name' => $name], ['unit' => $unit, 'quantity' => 0]);
                $item->update(['unit' => $unit]);

                // Log the difference so an import is attributable like any other change.
                $delta = max(0, (float) $qty) - (float) $item->quantity;
                $item->recordMovement($delta, $delta > 0 ? 'restock' : 'correction', 'CSV import', null, auth()->user()?->name);
                $imported++;
            }
        });

        $note = $skipped > 0 ? " ({$skipped} row(s) skipped — missing name or quantity)" : '';

        return back()->with('success', "Imported {$imported} item(s) from the file.{$note}");
    }

    /* ==================== Material requests from orders ==================== */

    public function requests(): View
    {
        $this->assertAccess();

        return view('inventory.requests', [
            'pending' => MaterialRequest::with('order')->where('status', 'pending')->orderBy('id')->get(),
            'decided' => MaterialRequest::with(['order', 'item', 'decider'])
                ->where('status', '!=', 'pending')->orderByDesc('decided_at')->limit(25)->get(),
            'items' => InventoryItem::orderBy('name')->get(),
        ]);
    }

    public function approve(Request $request, MaterialRequest $materialRequest): RedirectResponse
    {
        $this->assertAccess();
        // A pending request can be approved; a REJECTED one can be re-approved
        // once the material has been restocked.
        abort_unless(in_array($materialRequest->status, ['pending', 'rejected'], true), 403);

        $data = $request->validate([
            'inventory_item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            // Who is handing the materials out.
            'operator_name' => ['required', 'string', 'max:100'],
        ], ['operator_name.required' => 'Enter the name of the person issuing the materials.']);

        return DB::transaction(function () use ($data, $materialRequest, $request) {
            $item = InventoryItem::lockForUpdate()->find($data['inventory_item_id']);

            if ((float) $item->quantity < (float) $data['quantity']) {
                return back()->withErrors([
                    'quantity' => "Not enough stock of {$item->name} — requested ".(float) $data['quantity'].", only {$item->qtyForHumans()} {$item->unit} left. Restock or reject.",
                ]);
            }

            // Taken out for a job — logged against the order and whoever issued it.
            $item->recordMovement(
                -(float) $data['quantity'],
                'issued',
                'Issued for '.($materialRequest->order?->order_number ?? 'an order').' — '.$materialRequest->material,
                $materialRequest->production_order_id,
                $data['operator_name'],
            );

            $materialRequest->update([
                'status' => 'approved',
                'inventory_item_id' => $item->id,
                'quantity' => $data['quantity'],
                'decided_by' => $request->user()->id,
                'decided_at' => now(),
            ]);

            $this->closeRawMaterialsStep($materialRequest->order);

            return back()->with('success', "Approved — issued {$data['quantity']} {$item->unit} of {$item->name}. Stock left: {$item->fresh()->qtyForHumans()}.");
        });
    }

    public function reject(Request $request, MaterialRequest $materialRequest): RedirectResponse
    {
        $this->assertAccess();
        abort_unless($materialRequest->status === 'pending', 403);

        $data = $request->validate(['note' => ['required', 'string', 'max:500']]);

        $materialRequest->update([
            'status' => 'rejected',
            'note' => $data['note'],
            'decided_by' => $request->user()->id,
            'decided_at' => now(),
        ]);

        $this->closeRawMaterialsStep($materialRequest->order);

        return back()->with('success', 'Request rejected — the account officer and leader can see the reason.');
    }
}
