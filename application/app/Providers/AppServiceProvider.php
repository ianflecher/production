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

            // Money sitting on somebody's desk. The officer has recorded it and
            // the shop does not draw on it until finance agrees it landed, so
            // until then the job it belongs to has not started.
            if ($user && $user->canConfirmPayments()) {
                $view->with('paymentsToConfirm', \App\Models\Payment::awaitingConfirmation()->count());
            }

            if ($user && $user->isArtist()) {
                // What is waiting to be drawn — the layouts sit before any job
                // order exists, so nothing else in the nav counts them.
                //
                // DESIGNS, counted the way the Layouts page lists them, because
                // that is the page this badge points at.
                //
                // It counted INQUIRIES through Inquiry::drawnBy, which reads
                // layout_artist_id — the column from when a brief had one
                // artist and one layout. A brief now carries designs and each
                // design carries its own artist, so an artist handed designs on
                // a brief that is not in their name matched nothing here. Mick
                // had three to draw and no badge at all, and the number was
                // wrong for six of the seven artists.
                //
                // "To draw" rather than everything on the page: a design already
                // handed back is waiting on the client and there is nothing to
                // pick up. The page prints "to draw" against exactly these, so
                // the badge and the row it points at say the same thing.
                $view->with('layoutsToDraw', \App\Models\InquiryDesign::drawnBy($user)
                    ->where('status', \App\Models\InquiryDesign::STATUS_WITH_ARTIST)
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

            // The count of a queue, to the person who works that queue. The
            // desk was wearing a red 11 for requests it cannot open: a badge
            // that disagrees with the page it points at is worse than none.
            if ($user && $user->canDecideMaterialRequests()) {
                $shelf = $user->inventoryShelf();

                $view->with('pendingMaterials', \App\Models\MaterialRequest::query()
                    ->when($shelf !== null, fn ($q) => $q->where('kind', $shelf))
                    ->where('status', 'pending')
                    ->count());
            }
        });
    }
}
