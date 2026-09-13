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

            if ($user && ($user->isLeader() || $user->isArtistLead())) {
                // The same rule the Approvals page and the dashboard use, so
                // a badge can never disagree with the page it opens.
                $view->with('pendingApprovals', \App\Support\ApprovalQueue::countFor($user));
            }

            if ($user && $user->isArtist()) {
                // What is waiting to be drawn — the layouts sit before any job
                // order exists, so nothing else in the nav counts them.
                $view->with('layoutsToDraw', \App\Models\Inquiry::drawnBy($user)
                    ->where('layout_status', \App\Models\Inquiry::LAYOUT_WITH_ARTIST)
                    ->count());

                // Orders on the bench, counted the same way My Tasks groups
                // them: a step that is theirs and open, on an order that is
                // still alive. A badge that disagrees with the page it points
                // at is worse than no badge.
                $view->with('myActiveOrders', Task::where('assigned_to', $user->id)
                    ->whereNotIn('status', ['todo', 'complete', 'cancelled'])
                    ->whereHas('order', fn ($q) => $q->where('status', '!=', 'cancelled'))
                    ->distinct()
                    ->count('production_order_id'));
            }

            if ($user && $user->isSales()) {
                // Work still on the books. Counted the way the Orders page
                // opens — everything that is not finished — so the number and
                // the list agree. Cancelled jobs are not waiting on anybody.
                $view->with('openOrders', \App\Models\ProductionOrder::where('created_by', $user->id)
                    ->whereNotIn('status', ['complete', 'cancelled'])
                    ->count());

            }

            // Samples waiting on the account officer. Asked of everyone who can
            // open that page, not only the sales role: a leader holding the
            // order desk approves these too, and counting it for her alone was
            // the difference between work being seen and work sitting there.
            if ($user && $user->canCreateOrders()) {
                $view->with('pendingSamples', Task::where('status', 'for_checking')
                    ->where('approver_role', 'sales')
                    ->whereHas('order', function ($q) use ($user) {
                        $q->where('status', 'active');

                        // Scoped the same way the page is: an account officer
                        // reviews their own orders, a leader sees them all. A
                        // badge that disagrees with the page it opens is worse
                        // than no badge.
                        if ($user->isSales()) {
                            $q->where('created_by', $user->id);
                        }
                    })
                    ->count());
            }

            if ($user && $user->canManageInventory()) {
                $view->with('pendingMaterials', \App\Models\MaterialRequest::where('status', 'pending')->count());
            }
        });
    }
}
