<?php

namespace App\Http\Controllers;

use App\Models\ProductItem;
use App\Models\ProductMovement;
use App\Models\ProductReceipt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Finished-products inventory. Products are stocked automatically when an order
 * finishes (see ProductionOrder::stockFinishedProducts); here they can be
 * adjusted, released to clients, or added by hand.
 */
class ProductInventoryController extends Controller
{
    private function assertAccess(): void
    {
        abort_unless(auth()->user()->canManageProducts(), 403);
    }

    public function index(Request $request): View
    {
        $this->assertAccess();

        $search = trim((string) $request->query('q', ''));

        // Searched on the server so it reaches the whole product list, not just
        // the page being shown.
        $matching = fn ($q) => $q->when($search !== '', fn ($w) => $w->where(fn ($x) => $x
            ->where('name', 'like', "%{$search}%")
            ->orWhere('unit', 'like', "%{$search}%")));

        // Only what is actually on the shelf. Finished goods are made to order,
        // so every one of them reaches zero the day the client collects it —
        // leaving those in a stock list (and counting them as "out of stock",
        // in red) files completed work as a shortage.
        $items = ProductItem::query()
            ->where('quantity', '>', 0)
            ->tap($matching)
            ->orderBy('name')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // Gone, but not deleted: what was handed over and when is the answer to
        // "did they ever get it?", which somebody asks eventually.
        $handedOver = ProductItem::query()
            ->where('quantity', '<=', 0)
            ->whereHas('movements', fn ($q) => $q->where('reason', 'released'))
            ->with(['movements' => fn ($q) => $q->where('reason', 'released')->latest('id')->limit(1)])
            ->tap($matching)
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE, ['*'], 'gone_page')
            ->withQueryString();

        // The receiving queue holds one row per finished order until someone
        // confirms it, so it is paged too. It needs its own page parameter —
        // two paginators on one screen would otherwise move together.
        $pending = ProductReceipt::pending()
            ->with('order')
            ->orderBy('id')
            ->paginate(self::PER_PAGE, ['*'], 'receive_page')
            ->withQueryString();

        // Orders sitting finished, waiting to be handed to the client. This is
        // the last step of the pipeline and it belongs to whoever is holding
        // the boxes — it used to sit on the account officer's sample review,
        // where they could confirm a handover they were not part of.
        $toRelease = \App\Models\Task::with(['order.client', 'order.payments'])
            ->where('department', 'Release to client')
            ->where('status', 'for_checking')
            ->whereHas('order', fn ($q) => $q->where('status', 'active'))
            ->orderBy('id')
            ->get();

        return view('products.index', [
            'items' => $items,
            'search' => $search,
            'toRelease' => $toRelease,
            'handedOver' => $handedOver,
            // Counted across all products, so it doesn't change as you page.
            'totalCount' => ProductItem::where('quantity', '>', 0)->count(),
            'pending' => $pending,
            'pendingCount' => $pending->total(),
            'movements' => ProductMovement::with(['item', 'user', 'order'])
                ->latest('id')->limit(25)->get(),
        ]);
    }

    /**
     * Confirm how many of a finished product were actually received in person.
     * That quantity — not the order's expected number — is what goes to stock.
     */
    public function receive(Request $request, ProductReceipt $receipt): RedirectResponse
    {
        $this->assertAccess();
        abort_unless($receipt->status === 'pending', 403);

        $data = $request->validate([
            'operator_name' => ['required', 'string', 'max:100'],
        ], ['operator_name.required' => 'Enter the name of the person who received the products.']);

        // The received quantity is simply what the order expected — the desk only
        // confirms WHO received it, not a re-count.
        $qty = (float) $receipt->expected_quantity;

        $product = ProductItem::firstOrCreate(
            ['name' => $receipt->name],
            ['unit' => $receipt->unit ?: 'pcs', 'quantity' => 0]
        );

        if ($qty > 0) {
            $product->recordMovement(
                $qty,
                'received',
                'Received from order '.($receipt->order?->order_number ?? '—'),
                $receipt->production_order_id,
                $data['operator_name'],
            );
        }

        $receipt->update([
            'status' => 'received',
            'received_quantity' => $qty,
            'product_item_id' => $product->id,
            'received_by' => $data['operator_name'],
            'received_at' => now(),
        ]);

        // The inventory desk works this stage from here, not the station board.
        // Once everything from the order has been counted in, the "Inventory"
        // step is finished — which completes the order.
        $order = $receipt->order;
        $note = '';

        if ($order && ! ProductReceipt::where('production_order_id', $order->id)->pending()->exists()) {
            $task = $order->tasks()
                ->where('department', 'Inventory')
                ->whereNotIn('status', ['complete', 'cancelled'])
                ->first();

            if ($task) {
                // The desk just typed who received them. Put that on the step,
                // so the pipeline names the person instead of showing "—" for
                // a handover the system had the name for all along.
                $task->update(['operator_name' => trim($data['operator_name'])]);
                $task->forceComplete();
                $note = ' All products counted in — '.$order->order_number.' is finished.';
            }
        }

        return back()->with('success', "Received {$qty} {$product->unit} of {$receipt->name} into inventory.".$note);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->assertAccess();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'unit' => ['required', 'string', 'max:30'],
            'quantity' => ['required', 'numeric', 'min:0', 'max:999999999'],
            // Accounts are shared, so the person adding it types their name.
            'operator_name' => ['required', 'string', 'max:100'],
        ], ['operator_name.required' => 'Enter the name of the person adding the stock.']);

        $item = ProductItem::firstOrCreate(
            ['name' => $data['name']],
            ['unit' => $data['unit'], 'quantity' => 0]
        );
        $item->update(['unit' => $data['unit']]);
        $item->recordMovement((float) $data['quantity'], 'added', 'Added by hand', null, $data['operator_name']);

        return back()->with('success', "{$data['name']} added to product inventory.");
    }

    public function update(Request $request, ProductItem $product): RedirectResponse
    {
        $this->assertAccess();

        $data = $request->validate([
            'quantity' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'unit' => ['required', 'string', 'max:30'],
            'note' => ['nullable', 'string', 'max:255'],
            'operator_name' => ['required', 'string', 'max:100'],
        ], ['operator_name.required' => 'Enter the name of the person changing the stock.']);

        // Log the difference as stock in/out so the change is attributable.
        $delta = (float) $data['quantity'] - (float) $product->quantity;
        $product->update(['unit' => $data['unit']]);
        $product->recordMovement($delta, $delta > 0 ? 'restock' : 'correction', $data['note'] ?? null, null, $data['operator_name']);

        return back()->with('success', "{$product->name} updated — stock is now {$product->fresh()->qtyForHumans()} {$product->unit}.");
    }

    /** Client received / product moved out. */
    public function deduct(Request $request, ProductItem $product): RedirectResponse
    {
        $this->assertAccess();

        $data = $request->validate([
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'note' => ['nullable', 'string', 'max:255'],
            'operator_name' => ['required', 'string', 'max:100'],
        ], ['operator_name.required' => 'Enter the name of the person releasing the product.']);

        if ((float) $product->quantity < (float) $data['quantity']) {
            return back()->withErrors([
                'quantity' => "Not enough stock of {$product->name} — only {$product->qtyForHumans()} {$product->unit} left.",
            ]);
        }

        $product->recordMovement(
            -(float) $data['quantity'],
            'released',
            $data['note'] ?? 'Released / delivered to client',
            null,
            $data['operator_name'],
        );

        return back()->with('success', "Released {$data['quantity']} {$product->unit} of {$product->name}. Stock left: {$product->fresh()->qtyForHumans()}.");
    }

    public function destroy(ProductItem $product): RedirectResponse
    {
        $this->assertAccess();

        $product->delete();

        return back()->with('success', "{$product->name} removed from product inventory.");
    }
}
