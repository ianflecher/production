<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\MaterialRequest;
use App\Models\StockMovement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

    public function index(Request $request): View
    {
        $this->assertAccess();

        $search = trim((string) $request->query('q', ''));
        $category = (string) $request->query('category', '');
        $color = (string) $request->query('color', '');
        $size = (string) $request->query('size', '');

        if (! array_key_exists($category, InventoryItem::CATEGORIES)) {
            $category = '';
        }

        // The stock sheet reaches thousands of rows on a full inventory, so it
        // is searched and filtered in the database and shown a page at a time.
        $filtered = fn ($q) => $q
            ->when($search !== '', fn ($w) => $w->where(fn ($s) => $s
                ->where('name', 'like', "%{$search}%")
                ->orWhere('color', 'like', "%{$search}%")
                ->orWhere('size', 'like', "%{$search}%")
                ->orWhere('unit', 'like', "%{$search}%")))
            ->when($category !== '', fn ($w) => $w->where('category', $category))
            ->when($color !== '', fn ($w) => $w->where('color', $color))
            ->when($size !== '', fn ($w) => $w->where('size', $size));

        $items = InventoryItem::query()
            ->tap($filtered)
            // Received / Less are summed in the query rather than per row —
            // with a full stock sheet loaded that is two queries instead of
            // two thousand.
            ->withSum(['movements as received_sum' => fn ($q) => $q
                ->where('direction', StockMovement::IN)
                ->where('reason', '!=', 'added'), ], 'quantity')
            ->withSum(['movements as less_sum' => fn ($q) => $q
                ->where('direction', StockMovement::OUT), ], 'quantity')
            ->orderBy('name')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // Colour and size only offer what the chosen category actually has —
        // picking BOND PAPER shouldn't leave you scrolling past shirt colours
        // and shirt sizes that can never match.
        $inCategory = fn () => InventoryItem::query()
            ->when($category !== '', fn ($w) => $w->where('category', $category));

        $distinct = fn (string $column) => $inCategory()
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()
            ->orderBy($column)
            ->pluck($column);

        return view('inventory.index', [
            'items' => $items,
            'search' => $search,
            'category' => $category,
            'color' => $color,
            'size' => $size,
            'colorOptions' => $distinct('color'),
            'sizeOptions' => $distinct('size'),
            // Counted across the whole sheet, so these don't shift as you page.
            'totalCount' => InventoryItem::count(),
            'outCount' => InventoryItem::where('quantity', '<=', 0)->count(),
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
            ->paginate(self::PER_PAGE)
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
            // A picture of the material, so the floor can see what to pull.
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ], ['operator_name.required' => 'Enter the name of the person moving the stock.']);

        // Log the difference as stock in/out so the change is attributable.
        $delta = (float) $data['quantity'] - (float) $item->quantity;
        $item->update(['unit' => $data['unit']]);

        if ($request->hasFile('photo')) {
            // Drop the previous picture so old files don't pile up.
            if ($item->photo && Storage::disk('public')->exists($item->photo)) {
                Storage::disk('public')->delete($item->photo);
            }

            $item->update(['photo' => $request->file('photo')->store('inventory-photos', 'public')]);
        }

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

        // The shop's own stock sheet exports a wider layout than name/unit/qty,
        // so it is read on its own terms rather than being reformatted by hand.
        if ($map = $this->stockSheetColumns($rows)) {
            return $this->importStockSheet($rows, $map);
        }

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

    /**
     * Recognise the shop's RAW MATERIALS STOCKS export and return where its
     * columns are, or null when this is the plain name/unit/quantity file.
     *
     * @return array<string, int>|null
     */
    private function stockSheetColumns(array $rows): ?array
    {
        foreach (array_slice($rows, 0, 5) as $row) {
            $head = array_map(fn ($c) => strtoupper(trim((string) $c)), $row);

            $desc = array_search('DESCRIPTION', $head, true);
            $remaining = array_search('REMAINING', $head, true);

            if ($desc === false || $remaining === false) {
                continue;
            }

            return [
                'header' => (int) array_search($row, $rows, true),
                'group' => (int) (array_search('TYPE OF FABRIC', $head, true) ?: -1),
                'name' => (int) $desc,
                'beginning' => (int) (array_search('BEG BAL', $head, true) ?: -1),
                'received' => (int) (array_search('RECEIVED', $head, true) ?: -1),
                'less' => (int) (array_search('LESS', $head, true) ?: -1),
                'remaining' => (int) $remaining,
                'notes' => (int) (array_search('NOTES', $head, true) ?: -1),
            ];
        }

        return null;
    }

    /**
     * Load the stock sheet. The group name is only written on the first row of
     * each block, so it carries down; a row with no description is a spacer or
     * the totals line and is skipped.
     */
    private function importStockSheet(array $rows, array $map): RedirectResponse
    {
        $cell = function (array $row, int $i): string {
            return $i < 0 ? '' : trim((string) ($row[$i] ?? ''));
        };
        $number = fn (string $v): float => is_numeric(str_replace(',', '', $v))
            ? (float) str_replace(',', '', $v)
            : 0.0;

        $imported = 0;
        $skipped = 0;
        $group = '';
        $operator = auth()->user()?->name;

        DB::transaction(function () use ($rows, $map, $cell, $number, &$imported, &$skipped, &$group, $operator) {
            foreach (array_slice($rows, $map['header'] + 1) as $row) {
                // The group is written once per block and inherited below it.
                if (($g = $cell($row, $map['group'])) !== '') {
                    $group = $g;
                }

                $name = $cell($row, $map['name']);
                if ($name === '') {
                    $skipped++;   // spacer row, or the sheet's totals line

                    continue;
                }

                $beginning = $number($cell($row, $map['beginning']));
                $received = $number($cell($row, $map['received']));
                $less = $number($cell($row, $map['less']));

                $item = InventoryItem::withTrashed()->firstOrNew(['name' => $name]);
                if ($item->trashed()) {
                    $item->restore();
                }

                // The sheet keeps size and colour inside the description, so
                // they are read off the name — that is what the inventory's
                // size and colour filters work from.
                $item->fill([
                    'category' => array_key_exists($group, InventoryItem::CATEGORIES) ? $group : null,
                    'size' => $item->size ?: \App\Services\MaterialName::size($name),
                    'color' => $item->color ?: \App\Services\MaterialName::color($name),
                    'unit' => $item->unit ?: 'pcs',
                    'quantity' => 0,
                    'beginning_stock' => $beginning,
                ])->save();

                // Replay the sheet's own history so Received / Less / Total on
                // the page match the spreadsheet rather than showing zero.
                $item->movements()->delete();
                $item->update(['quantity' => 0]);

                $item->recordMovement($beginning, 'added', 'Opening balance (stock sheet import)', null, $operator);
                $item->recordMovement($received, 'restock', 'Received (stock sheet import)', null, $operator);
                $item->recordMovement(-$less, 'used', 'Issued out (stock sheet import)', null, $operator);

                $imported++;
            }
        });

        $note = $skipped > 0 ? " ({$skipped} row(s) skipped — no description)" : '';

        return back()->with('success', "Imported {$imported} material(s) from the stock sheet.{$note}");
    }

    /* ==================== Material requests from orders ==================== */

    public function requests(Request $request): View
    {
        $this->assertAccess();

        // Same box as every other list: find a material, or the job it is for.
        $search = trim((string) $request->query('q', ''));

        $matching = fn ($q) => $q->when($search !== '', fn ($w) => $w->where(fn ($s) => $s
            ->where('material', 'like', "%{$search}%")
            ->orWhereHas('order', fn ($o) => $o
                ->where('order_number', 'like', "%{$search}%")
                ->orWhere('customer_name', 'like', "%{$search}%"))));

        return view('inventory.requests', [
            'search' => $search,
            // A queue that grows while nobody acts on it, so it is paged. The
            // $items list below stays whole on purpose — it fills the material
            // dropdown and matches names, so it is not a list being read.
            'pending' => MaterialRequest::with('order')
                ->where('status', 'pending')
                ->tap($matching)
                ->orderBy('id')
                ->paginate(self::PER_PAGE)
                ->withQueryString(),
            'decided' => MaterialRequest::with(['order', 'item', 'decider'])
                ->where('status', '!=', 'pending')
                ->tap($matching)
                ->orderByDesc('decided_at')->limit(25)->get(),
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
                'decided_by_name' => $data['operator_name'],
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

        $data = $request->validate([
            'note' => ['required', 'string', 'max:500'],
            // Shared login: say who is turning it down, same as issuing.
            'operator_name' => ['required', 'string', 'max:100'],
        ], ['operator_name.required' => 'Enter the name of the person rejecting this.']);

        $materialRequest->update([
            'status' => 'rejected',
            'decided_by_name' => $data['operator_name'],
            'note' => $data['note'],
            'decided_by' => $request->user()->id,
            'decided_at' => now(),
        ]);

        $this->closeRawMaterialsStep($materialRequest->order);

        return back()->with('success', 'Request rejected — the account officer and leader can see the reason.');
    }

    /**
     * Put back materials that went out but were not used.
     *
     * Issuing is typed by hand against a request that never says how many are
     * needed, so handing out 100 when the job wanted 55 is a normal morning.
     * Until now the only way back was a blank "correction" on the item, which
     * left the request still claiming 100 went to that order and the 45 with
     * no explanation attached to anything.
     *
     * This adds the stock back, writes it against the same order, and brings
     * the request's own figure down to what the job actually consumed — so
     * costing reads 55 and the shelf count agrees with the shelf.
     */
    public function returnToStock(Request $request, MaterialRequest $materialRequest): RedirectResponse
    {
        $this->assertAccess();
        abort_unless($materialRequest->status === 'approved', 403);
        abort_unless($materialRequest->item, 404);

        $issued = (float) $materialRequest->quantity;

        $data = $request->validate([
            // You cannot hand back more than went out on this request.
            'quantity' => ['required', 'numeric', 'gt:0', 'max:'.$issued],
            'operator_name' => ['required', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'quantity.max' => 'Only '.rtrim(rtrim(number_format($issued, 2), '0'), '.').' went out on this request — you cannot return more than that.',
            'operator_name.required' => 'Enter the name of the person returning the materials.',
        ]);

        return DB::transaction(function () use ($data, $materialRequest) {
            $item = InventoryItem::lockForUpdate()->find($materialRequest->inventory_item_id);
            $back = (float) $data['quantity'];

            $item->recordMovement(
                $back,
                'returned',
                'Returned unused from '.($materialRequest->order?->order_number ?? 'an order')
                    .' — '.$materialRequest->material
                    .(filled($data['note'] ?? null) ? ' ('.$data['note'].')' : ''),
                $materialRequest->production_order_id,
                $data['operator_name'],
            );

            // What the job actually used is what it should be costed for.
            $materialRequest->update(['quantity' => (float) $materialRequest->quantity - $back]);

            return back()->with('success', sprintf(
                'Returned %s %s of %s. This order now shows %s used, and stock is back to %s.',
                rtrim(rtrim(number_format($back, 2), '0'), '.'),
                $item->unit,
                $item->name,
                rtrim(rtrim(number_format((float) $materialRequest->fresh()->quantity, 2), '0'), '.'),
                $item->fresh()->qtyForHumans(),
            ));
        });
    }
}
