<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ProductionOrder;

/**
 * Shared order-visibility rule for the order-related controllers.
 *
 * Account officers may only touch the orders they created. Leaders and the
 * super admin can access every order.
 */
trait AuthorizesOrderAccess
{
    protected function assertOrderVisible(ProductionOrder $order): void
    {
        $user = auth()->user();

        if ($user->isSales() && $order->created_by !== $user->id) {
            abort(403);
        }
    }
}
