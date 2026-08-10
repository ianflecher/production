<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BookkeepingController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\JobOrderController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\OrderDesignBriefController;
use App\Http\Controllers\OrderDocumentController;
use App\Http\Controllers\OrderReferenceFileController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProductionOrderController;
use App\Http\Controllers\SystemHealthController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\AccountController;
use Illuminate\Support\Facades\Route;


Route::redirect('/', '/dashboard');

// ============ Guest routes ============
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])
        ->middleware('throttle:20,1')
        ->name('login.attempt');
});

// ============ Public client questionnaire (random-token link, no login) ============
// The account officer shares a link containing a random, unguessable token; the
// client fills the questionnaire and their answers are saved to the order's
// design brief. The token IS the secret (bound by brief_token), and the link
// expires after ProductionOrder::BRIEF_LINK_DAYS (checked in the controller).
// Throttled because this is the only route reachable without logging in: it caps
// automated guessing of brief_token and stops the upload form being hammered.
// The limits are far above what a real client filling one form ever needs.
Route::get('/imprint-customs/design-questionnaire/{order:brief_token}', [\App\Http\Controllers\ClientDesignBriefController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('client.design-brief');
Route::post('/imprint-customs/design-questionnaire/{order:brief_token}', [\App\Http\Controllers\ClientDesignBriefController::class, 'submit'])
    ->middleware('throttle:10,1')
    ->name('client.design-brief.submit');

// ============ Authenticated routes (active accounts only) ============
Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Live updates: pages poll this and reload themselves when data changed.
    Route::get('/poll/version', fn () => ['v' => \App\Services\DataVersion::current()])->name('poll.version');

    // Web Push: browser opts in (works even when closed). This is how alerts
    // reach someone now — the server sends them. Nothing asks on a timer.
    Route::post('/push/subscribe', [\App\Http\Controllers\NotificationController::class, 'subscribe'])->name('push.subscribe');
    Route::post('/push/unsubscribe', [\App\Http\Controllers\NotificationController::class, 'unsubscribe'])->name('push.unsubscribe');

    // -------- Station board: who is on each machine, breaks & shift changes --------
    Route::get('/stations', [\App\Http\Controllers\StationController::class, 'index'])->name('stations.index');
    // The approved package (mockup, template, job order, production details) —
    // the floor needs to see exactly what the leader signed off before running it.
    Route::get('/orders/{order}/package', [JobOrderController::class, 'completeJobOrder'])
        ->whereNumber('order')->name('orders.package');
    Route::post('/stations/start', [\App\Http\Controllers\StationController::class, 'start'])->name('stations.start');
    Route::post('/station-sessions/{stationSession}/end', [\App\Http\Controllers\StationController::class, 'end'])
        ->whereNumber('stationSession')->name('stations.end');

    // -------- Messages: one conversation per job order, for everyone on it --------
    Route::get('/messages', [MessageController::class, 'index'])->name('messages.index');
    Route::get('/messages/unread', [MessageController::class, 'unread'])->name('messages.unread');
    Route::get('/message-files/{file}', [MessageController::class, 'file'])
        ->whereNumber('file')->name('messages.file');
    Route::get('/messages/{order}', [MessageController::class, 'show'])
        ->whereNumber('order')->name('messages.show');
    Route::post('/messages/{order}', [MessageController::class, 'store'])
        ->whereNumber('order')->middleware('throttle:60,1')->name('messages.store');

    // -------- Raw materials inventory (supply chain + leaders; checked in controller) --------
    Route::get('/inventory', [\App\Http\Controllers\InventoryController::class, 'index'])->name('inventory.index');
    Route::post('/inventory', [\App\Http\Controllers\InventoryController::class, 'store'])->name('inventory.store');
    Route::post('/inventory/{item}', [\App\Http\Controllers\InventoryController::class, 'update'])->whereNumber('item')->name('inventory.update');
    Route::post('/inventory/{item}/delete', [\App\Http\Controllers\InventoryController::class, 'destroy'])->whereNumber('item')->name('inventory.destroy');
    Route::get('/inventory-history', [\App\Http\Controllers\InventoryController::class, 'history'])->name('inventory.history');
    Route::get('/inventory-export', [\App\Http\Controllers\InventoryController::class, 'export'])->name('inventory.export');
    Route::post('/inventory-import', [\App\Http\Controllers\InventoryController::class, 'import'])->name('inventory.import');
    Route::get('/material-requests', [\App\Http\Controllers\InventoryController::class, 'requests'])->name('inventory.requests');
    Route::post('/material-requests/{materialRequest}/approve', [\App\Http\Controllers\InventoryController::class, 'approve'])->name('inventory.requests.approve');
    Route::post('/material-requests/{materialRequest}/reject', [\App\Http\Controllers\InventoryController::class, 'reject'])->name('inventory.requests.reject');

    // -------- Finished-products inventory (products/inventory desk + leaders; checked in controller) --------
    Route::get('/products', [\App\Http\Controllers\ProductInventoryController::class, 'index'])->name('products.index');
    Route::post('/products/receipts/{receipt}/receive', [\App\Http\Controllers\ProductInventoryController::class, 'receive'])->whereNumber('receipt')->name('products.receive');
    Route::post('/products', [\App\Http\Controllers\ProductInventoryController::class, 'store'])->name('products.store');
    Route::post('/products/{product}', [\App\Http\Controllers\ProductInventoryController::class, 'update'])->whereNumber('product')->name('products.update');
    Route::post('/products/{product}/deduct', [\App\Http\Controllers\ProductInventoryController::class, 'deduct'])->whereNumber('product')->name('products.deduct');
    Route::post('/products/{product}/delete', [\App\Http\Controllers\ProductInventoryController::class, 'destroy'])->whereNumber('product')->name('products.destroy');

    Route::get('/account', [PasswordController::class, 'edit'])->name('password.edit');
    Route::post('/account/name', [PasswordController::class, 'updateName'])
        ->middleware('throttle:20,1')
        ->name('account.name');
    Route::post('/account/password', [PasswordController::class, 'update'])
        ->middleware('throttle:10,1')
        ->name('password.update');

    // -------- Agent: only their own tasks, scoped by assigned_to --------
    Route::get('/my-tasks', [TaskController::class, 'mine'])->name('tasks.mine');
    Route::get('/my-tasks/{taskId}', [TaskController::class, 'showMine'])->whereNumber('taskId')->name('tasks.show');
    Route::get('/my-tasks/{taskId}/job-order', [TaskController::class, 'jobOrder'])->whereNumber('taskId')->name('tasks.job-order');
    Route::get('/my-tasks/{taskId}/reference', [TaskController::class, 'references'])->whereNumber('taskId')->name('tasks.references');
    Route::post('/my-tasks/{taskId}/start', [TaskController::class, 'start'])->whereNumber('taskId')->name('tasks.start');
    // Correct a file path already handed to production (typo, moved file).
    Route::post('/my-tasks/{taskId}/path', [TaskController::class, 'updatePath'])->whereNumber('taskId')->name('tasks.path.update');
    Route::post('/my-tasks/{taskId}/hold', [TaskController::class, 'hold'])->whereNumber('taskId')->name('tasks.hold');
    Route::post('/my-tasks/{taskId}/resume', [TaskController::class, 'resume'])->whereNumber('taskId')->name('tasks.resume');
    Route::post('/my-tasks/{taskId}/submit', [TaskController::class, 'submit'])->whereNumber('taskId')->name('tasks.submit');

    // Submitted work files — access checked per-file in the controller.
    Route::get('/task-files/{file}/download', [TaskController::class, 'downloadFile'])
        ->whereNumber('file')->name('tasks.file.download');
    Route::get('/task-files/{file}/view', [TaskController::class, 'viewFile'])
        ->whereNumber('file')->name('tasks.file.view');

    // Job order client-reference files — access checked per-file in the controller.
    Route::get('/job-order-files/{file}/view', [OrderReferenceFileController::class, 'viewReferenceFile'])
        ->whereNumber('file')->name('job-order-files.view');
    Route::get('/job-order-files/{file}/download', [OrderReferenceFileController::class, 'downloadReferenceFile'])
        ->whereNumber('file')->name('job-order-files.download');

    // -------- Approve / revise: sales decide samples, leaders decide the rest
    // (the controller enforces which role owns each task).
    Route::middleware('role:sales,leader,super_admin')->group(function () {
        Route::post('/tasks/{task}/approve', [TaskController::class, 'approve'])->name('tasks.approve');
        Route::post('/tasks/{task}/revision', [TaskController::class, 'requestRevision'])->name('tasks.revision');
        // Client rejected the physical sample — send it back to a production step.
        Route::post('/tasks/{task}/return-to-stage', [TaskController::class, 'returnSampleToStage'])->name('tasks.return-to-stage');
        // Approve / revise the whole design package (mockup + template) at once.
        Route::post('/orders/{order}/approve-package', [TaskController::class, 'approvePackage'])->whereNumber('order')->name('tasks.approve-package');
        Route::post('/orders/{order}/revise-package', [TaskController::class, 'revisePackage'])->whereNumber('order')->name('tasks.revise-package');
    });

    // -------- Sales: samples waiting for the client's decision --------
    Route::middleware('role:sales,super_admin')->group(function () {
        Route::get('/sample-review', [TaskController::class, 'sampleReview'])->name('sample.review');
        Route::get('/job-orders/{order}/create', [JobOrderController::class, 'createJobOrder'])->name('job-orders.create');
        Route::post('/job-orders/{order}', [JobOrderController::class, 'storeJobOrder'])->name('job-orders.store');
        Route::get('/job-orders/{order}/edit', [JobOrderController::class, 'editJobOrder'])->name('job-orders.edit');
        Route::post('/job-orders/{order}/update', [JobOrderController::class, 'updateJobOrder'])->name('job-orders.update');
        Route::get('/job-orders/{order}/production', [JobOrderController::class, 'productionJobOrder'])->name('job-orders.production');
        Route::post('/job-orders/{order}/production', [JobOrderController::class, 'updateProductionDetails'])->name('job-orders.production.update');
        Route::post('/job-orders/{order}/send', [JobOrderController::class, 'sendJobOrderToArtist'])->name('job-orders.send');
        Route::post('/job-orders/{order}/reference', [OrderReferenceFileController::class, 'uploadReferenceFile'])->name('job-orders.reference');
        Route::post('/job-order-files/{file}/delete', [OrderReferenceFileController::class, 'deleteReferenceFile'])->whereNumber('file')->name('job-order-files.delete');
        Route::post('/job-order-files/{file}/kind', [OrderReferenceFileController::class, 'markReferenceKind'])->whereNumber('file')->name('job-order-files.kind');
    });

    // -------- Order intake: Sales (and Super Admin) create orders --------
    Route::middleware('role:sales,super_admin')->group(function () {
        Route::get('/orders/create', [ProductionOrderController::class, 'create'])->name('orders.create');
        Route::post('/orders', [ProductionOrderController::class, 'store'])->name('orders.store');
        Route::get('/orders/{order}/edit', [ProductionOrderController::class, 'edit'])->whereNumber('order')->name('orders.edit');
        Route::post('/orders/{order}', [ProductionOrderController::class, 'update'])->whereNumber('order')->name('orders.update');
        Route::post('/orders/{order}/payment', [PaymentController::class, 'recordPayment'])->name('orders.payment');
        Route::post('/orders/{order}/send-for-layout', [ProductionOrderController::class, 'sendForLayout'])->whereNumber('order')->name('orders.send-for-layout');

        // Client design questionnaire → copy-paste ChatGPT prompt.
        Route::get('/orders/{order}/design-brief', [OrderDesignBriefController::class, 'designBrief'])->whereNumber('order')->name('orders.design-brief');
        Route::post('/orders/{order}/design-brief', [OrderDesignBriefController::class, 'saveDesignBrief'])->whereNumber('order')->name('orders.design-brief.save');
        // Reopen the single-use client link for one more submission.
        Route::post('/orders/{order}/design-brief/reopen', [OrderDesignBriefController::class, 'reopenClientBrief'])->whereNumber('order')->name('orders.design-brief.reopen');

        // Live due-date capacity hint on the order forms.
        Route::get('/order-capacity', [ProductionOrderController::class, 'capacity'])->name('orders.capacity');

        // Client documents: DR (no VAT) / PQ (+12% VAT) — before and after payment.
        Route::get('/orders/{order}/document/{type}', [OrderDocumentController::class, 'document'])
            ->whereNumber('order')->whereIn('type', ['dr', 'pq'])->name('orders.document');
        Route::post('/orders/{order}/document/{type}', [OrderDocumentController::class, 'saveDocument'])
            ->whereNumber('order')->whereIn('type', ['dr', 'pq'])->name('orders.document.save');
        Route::post('/orders/{order}/document/{type}/refresh', [OrderDocumentController::class, 'refreshDocument'])
            ->whereNumber('order')->whereIn('type', ['dr', 'pq'])->name('orders.document.refresh');

        // Contract / payment proof / signed copy placed on the sheet.
        Route::post('/orders/{order}/document/{type}/attach', [OrderDocumentController::class, 'uploadDocumentFile'])
            ->whereNumber('order')->whereIn('type', ['dr', 'pq'])->name('orders.document.attach');
        Route::post('/orders/{order}/document/{type}/attach/{index}/delete', [OrderDocumentController::class, 'deleteDocumentFile'])
            ->whereNumber('order')->whereIn('type', ['dr', 'pq'])->whereNumber('index')->name('orders.document.attach.delete');
        Route::get('/orders/{order}/document/{type}/attach/{index}', [OrderDocumentController::class, 'viewDocumentFile'])
            ->whereNumber('order')->whereIn('type', ['dr', 'pq'])->whereNumber('index')->name('orders.document.attach.view');
        Route::post('/orders/{order}/document/{type}/flatlay', [OrderDocumentController::class, 'uploadDocumentFlatlay'])
            ->whereNumber('order')->whereIn('type', ['dr', 'pq'])->name('orders.document.uploadFlatlay');
        Route::get('/orders/{order}/document/{type}/flatlay', [OrderDocumentController::class, 'viewDocumentFlatlay'])
            ->whereNumber('order')->whereIn('type', ['dr', 'pq'])->name('orders.document.flatlay');
    });

    // -------- Finance: all payments + proof (read-only) --------
    Route::middleware('role:finance,leader,super_admin')->group(function () {
        Route::get('/finance', [FinanceController::class, 'index'])->name('finance.index');
        Route::get('/finance/export', [FinanceController::class, 'export'])->name('finance.export');
        Route::get('/finance/payments/{payment}/proof', [FinanceController::class, 'proof'])
            ->whereNumber('payment')->name('finance.proof');

        // -------- Bookkeeping: money in vs money out, month by month --------
        Route::get('/books', [BookkeepingController::class, 'index'])->name('books.index');
        Route::post('/books/expenses', [BookkeepingController::class, 'store'])->name('books.expenses.store');
        Route::post('/books/expenses/{expense}/delete', [BookkeepingController::class, 'destroy'])
            ->whereNumber('expense')->name('books.expenses.destroy');
        Route::get('/books/expenses/{expense}/receipt', [BookkeepingController::class, 'receipt'])
            ->whereNumber('expense')->name('books.expenses.receipt');
        Route::get('/books/export', [BookkeepingController::class, 'export'])->name('books.export');
    });

    // -------- Order viewing + calendar: Sales, Leader, Super Admin, Mover --------
    // The mover reads job orders to chase progress round the floor. Read-only:
    // creating, editing, payments and approvals all live in other groups.
    Route::middleware('role:sales,leader,super_admin,mover')->group(function () {
        Route::get('/orders', [ProductionOrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/{order}', [ProductionOrderController::class, 'show'])->whereNumber('order')->name('orders.show');
        Route::get('/orders/{order}/job-order', [ProductionOrderController::class, 'jobOrder'])->whereNumber('order')->name('orders.job-order');
        // The whole package as one document (mockup, template, job order, production details).
        Route::get('/orders/{order}/mockup', [ProductionOrderController::class, 'mockup'])->whereNumber('order')->name('orders.mockup');
        Route::get('/orders/{order}/reference', [ProductionOrderController::class, 'references'])->whereNumber('order')->name('orders.references');
        Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar');
        Route::get('/payments/{payment}/proof', [PaymentController::class, 'proof'])
            ->whereNumber('payment')->name('payments.proof');
    });

    // -------- Production management: Leader / Super Admin --------
    Route::middleware('role:leader,super_admin')->group(function () {
        Route::post('/orders/{order}/status', [ProductionOrderController::class, 'updateStatus'])->name('orders.status');

        Route::get('/approvals', [TaskController::class, 'approvals'])->name('approvals');
        Route::post('/tasks/{task}/assign', [TaskController::class, 'assign'])->name('tasks.assign');
        Route::post('/tasks/{task}/unlock', [TaskController::class, 'unlock'])->name('tasks.unlock');
        Route::post('/tasks/{task}/complete', [TaskController::class, 'forceComplete'])->name('tasks.force-complete');

        // What has been going wrong, without opening the log file on the server.
        Route::get('/system/errors', [SystemHealthController::class, 'errors'])->name('system.errors');
        // Clearing one only records that somebody looked; the log is untouched.
        Route::post('/system/errors/dismiss', [SystemHealthController::class, 'dismiss'])->name('system.errors.dismiss');

        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::post('/users/{user}/toggle', [UserController::class, 'toggle'])->name('users.toggle');
        Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset');
        Route::post('/users/{user}/attendance', [UserController::class, 'markAttendance'])->name('users.attendance');
        Route::post('/users/{user}/team', [UserController::class, 'setTeam'])->name('users.team');
    });


Route::middleware('auth')->group(function () {
    Route::post('/account/profile-photo', [
        AccountController::class,
        'updatePhoto',
    ])->name('account.photo');

    Route::delete('/account/profile-photo', [
        AccountController::class,
        'deletePhoto',
    ])->name('account.photo.delete');
});

Route::delete('/users/{user}', [
    UserController::class,
    'destroy',
])->name('users.destroy');

});

use Illuminate\Support\Facades\DB;

/**
 * How far away the database is, split into the two things that actually cost
 * time. The original measured them together, which read as "the database is
 * slow" when nearly all of it was the cost of opening the connection.
 *
 * Behind a login: it reports where the database is and how it is reached, which
 * is nobody's business but the shop's.
 */
Route::get('/db-test', function () {
    // Time a connection of our own rather than dropping the app's: closing the
    // live one would break the request that is running and defeat the whole
    // point of holding connections open.
    $connecting = null;
    $config = config('database.connections.'.config('database.default'));

    if (($config['driver'] ?? null) === 'mysql') {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s',
            $config['host'], $config['port'] ?? 3306, $config['database']);

        $t = microtime(true);
        $probe = new PDO($dsn, $config['username'], $config['password'], $config['options'] ?? []);
        $connecting = (microtime(true) - $t) * 1000;
        $probe = null;
    }

    $t = microtime(true);
    DB::select('SELECT 1');                 // on the connection already open
    $queryOnly = (microtime(true) - $t) * 1000;

    $t = microtime(true);
    for ($i = 0; $i < 10; $i++) {
        DB::select('SELECT 1');
    }
    $perQuery = ((microtime(true) - $t) * 1000) / 10;

    // A typical page here runs about this many queries. Used to turn a
    // per-query figure into the number that actually matters — what the
    // latency costs a real page.
    $typicalPageQueries = 25;
    $pageCost = ($perQuery * $typicalPageQueries) / 1000;

    // Read the two numbers in the right order. Connection cost is paid ONCE per
    // request and DB_PERSISTENT removes it; per-query cost is paid by every
    // single query on the page and nothing but distance fixes it. Blaming the
    // connection when a bare SELECT 1 takes half a second sends you tuning
    // settings that cannot help.
    $verdict = match (true) {
        $connecting === null => 'Connection timing is only measured for MySQL.',

        $perQuery > 50 => sprintf(
            "THE DATABASE IS TOO FAR AWAY. A query that does no work takes\n".
            "%.0f ms, and that is not the database thinking — it is the round\n".
            "trip. Every query on the page pays it, so a normal page of about\n".
            "%d queries costs roughly %.1f SECONDS before the app does anything.\n".
            "\n".
            "Move the database into the same region as the deployment. That is\n".
            "the whole fix and it is on the hosting side, not in this repo.\n".
            "DB_PERSISTENT and the cache settings are worth having, but they\n".
            "save the connection and a few queries — they cannot touch the\n".
            "%.0f ms that every remaining query still pays.",
            $perQuery, $typicalPageQueries, $pageCost, $perQuery
        ),

        $connecting > 50 => "Queries are quick once the connection is open, but opening it is\n".
            "slow and that is paid on every request. Set DB_PERSISTENT=true so\n".
            'the connection is reused instead of rebuilt each time.',

        default => 'The database is close by and connecting is cheap. If pages still\n'.
            'feel slow, it is not the database.',
    };

    return response()->make(sprintf(
        "Opening a connection   : %s   <-- paid once per request\n".
        "One query once open    : %8.2f ms\n".
        "Average of ten queries : %8.2f ms   <-- paid by EVERY query on the page\n".
        "Connections reused     : %s\n".
        "\n".
        "A page of ~%d queries therefore costs about %.1f s in waiting alone.\n".
        "\n%s\n",
        $connecting === null ? '     n/a  ' : sprintf('%8.2f ms', $connecting),
        $queryOnly,
        $perQuery,
        ($config['options'][PDO::ATTR_PERSISTENT] ?? false) ? 'yes' : 'no (DB_PERSISTENT)',
        $typicalPageQueries,
        $pageCost,
        $verdict
    ), 200, ['Content-Type' => 'text/plain']);
})->middleware(['auth']);
