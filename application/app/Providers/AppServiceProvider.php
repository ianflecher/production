<?php

namespace App\Providers;

use App\Models\Task;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The framework's default pager is written for Tailwind, which this app
        // does not use. Ours is plain markup styled by app.css.
        Paginator::defaultView('pagination::imprint');

        // Windows/XAMPP: PHP can't create EC keys (needed to sign Web Push
        // messages) unless it knows where openssl.cnf is.
        if (! getenv('OPENSSL_CONF')) {
            foreach ([
                'C:\\xampp1\\php\\extras\\ssl\\openssl.cnf',
                'C:\\xampp1\\apache\\conf\\openssl.cnf',
            ] as $cnf) {
                if (is_file($cnf)) {
                    putenv('OPENSSL_CONF='.$cnf);
                    break;
                }
            }
        }

        // Sidebar badges: work waiting on the signed-in user.
        View::composer('layouts.app', function ($view) {
            $user = auth()->user();

            if ($user && $user->isLeader()) {
                // Count ROWS as shown on the Approvals page: the mockup + template
                // of an order are one "job package" row, everything else is one row
                // each — so the badge matches what the leader actually sees.
                $mockup = \App\Models\ProductionOrder::STAGE_MOCKUP;

                $packages = Task::with('order.jobOrder')
                    ->where('stage', $mockup)
                    ->where('approver_role', 'leader')
                    ->whereHas('order', fn ($q) => $q->where('status', 'active'))
                    ->get()
                    ->groupBy('production_order_id')
                    ->filter(fn ($group) => $group->every(fn ($t) => $t->status === 'for_checking')
                        && blank($group->first()->order->jobOrder?->leader_note))
                    ->count();

                $singles = Task::where('status', 'for_checking')
                    ->where('approver_role', 'leader')
                    ->where('stage', '!=', $mockup)
                    ->whereHas('order', fn ($q) => $q->where('status', 'active'))
                    ->count();

                $view->with('pendingApprovals', $packages + $singles);
            }

            if ($user && $user->isSales()) {
                // Account officers only review samples for their own orders, so the
                // badge must be scoped the same way as the Sample Review page.
                $view->with('pendingSamples', Task::where('status', 'for_checking')
                    ->where('approver_role', 'sales')
                    ->whereHas('order', fn ($q) => $q->where('status', 'active')->where('created_by', $user->id))
                    ->count());
            }

            if ($user && $user->canManageInventory()) {
                $view->with('pendingMaterials', \App\Models\MaterialRequest::where('status', 'pending')->count());
            }
        });
    }
}
