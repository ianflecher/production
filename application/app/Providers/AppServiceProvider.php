<?php

namespace App\Providers;

use App\Models\Task;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
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

        /*
         * An artist's queue follows the artists.
         *
         * Work is handed to a named person, so a queue sitting with somebody
         * who has gone home is a queue nobody is drawing. Signing off passes
         * on what they had NOT started; signing back in takes it back, unless
         * whoever received it has started or finished it.
         *
         * Wrapped because this must never be the reason somebody cannot sign
         * in or out: if the shuffle fails, the door still opens.
         */
        Event::listen(function (\Illuminate\Auth\Events\Logout $event) {
            $user = $event->user;

            if ($user instanceof \App\Models\User && $user->isArtist()) {
                try {
                    \App\Services\ArtistBench::handOver($user);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        });

        Event::listen(function (\Illuminate\Auth\Events\Login $event) {
            $user = $event->user;

            if ($user instanceof \App\Models\User && $user->isArtist()) {
                try {
                    \App\Services\ArtistBench::welcomeBack($user);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        });

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
                    // A pack he drew himself is not his to check, and the queue
                    // already drops it — counting it here put a 1 on a nav item
                    // that opens an empty page. See TaskController::approvals.
                    ->reject(fn ($group) => $user->isArtistLead() && ! $user->isLeader()
                        && $group->contains('assigned_to', $user->id))
                    ->count();

                $singles = Task::where('status', 'for_checking')
                    ->where('approver_role', 'leader')
                    ->where('stage', '!=', $mockup)
                    ->whereHas('order', fn ($q) => $q->where('status', 'active'))
                    ->count();

                // The artist leader's badge counts tech packs only — the rest of
                // the floor is not his queue.
                $view->with('pendingApprovals', $user->isArtistLead() ? $packages : $packages + $singles);
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
