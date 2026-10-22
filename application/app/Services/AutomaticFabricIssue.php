<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\ProductionOrder;
use Illuminate\Support\Facades\DB;

class AutomaticFabricIssue
{
    public static function run(ProductionOrder $order): int
    {
        return DB::transaction(function () use ($order) {
            // Serialize deductions for this order and recheck stock under locks.
            $order = ProductionOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->status !== 'active') return 0;
            $task = $order->tasks()->where('department', 'Raw materials')
                ->whereIn('status', ['ready', 'in_progress'])->lockForUpdate()->first();
            if (! $task) return 0;

            $requests = $order->materialRequests()->where('status', 'pending')
                ->where('kind', InventoryItem::KIND_FABRIC)->orderBy('id')->lockForUpdate()->get();
            if ($requests->isEmpty()) return 0;

            $plan = [];
            $totals = [];
            foreach ($requests as $request) {
                $candidates = $request->stockCandidates();
                $owed = round((float) $request->requested_quantity - (float) $request->issued_quantity, 2);
                if ($request->requested_quantity === null || $owed <= 0 || $candidates->count() !== 1) return 0;
                $id = $candidates->first()->id;
                $plan[] = [$request, $id, $owed];
                $totals[$id] = round(($totals[$id] ?? 0) + $owed, 2);
            }
            $stock = InventoryItem::whereIn('id', array_keys($totals))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($totals as $id => $quantity) {
                if (! isset($stock[$id]) || $stock[$id]->kind !== InventoryItem::KIND_FABRIC
                    || (float) $stock[$id]->quantity < $quantity) return 0;
            }

            $operator = 'System — automatic fabric issue';
            foreach ($plan as [$request, $id, $quantity]) {
                $stock[$id]->recordMovement(-$quantity, 'issued',
                    'Automatic fabric issue for '.$order->order_number.' — '.$request->label(), $order->id, $operator);
                $issued = (float) $request->issued_quantity + $quantity;
                $request->update([
                    'status' => 'approved', 'inventory_item_id' => $id,
                    'issued_quantity' => $issued, 'quantity' => $issued,
                    'decided_by' => null, 'decided_by_name' => $operator, 'decided_at' => now(),
                ]);
            }
            // Ready-made requests still require their normal desk handover.
            if (! $order->materialRequests()->where('status', 'pending')->exists()) {
                $task->update(['operator_name' => $operator]);
                $task->forceComplete();
                $task->update(['approved_by' => null]);
            }
            return count($plan);
        });
    }
}
