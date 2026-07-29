<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\ProductionOrderController;
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
Route::get('/imprint-customs/design-questionnaire/{order:brief_token}', [\App\Http\Controllers\ClientDesignBriefController::class, 'show'])
    ->name('client.design-brief');
Route::post('/imprint-customs/design-questionnaire/{order:brief_token}', [\App\Http\Controllers\ClientDesignBriefController::class, 'submit'])
    ->name('client.design-brief.submit');

// ============ Authenticated routes (active accounts only) ============
Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Live updates: pages poll this and reload themselves when data changed.
    Route::get('/poll/version', fn () => ['v' => \App\Services\DataVersion::current()])->name('poll.version');

    // Desktop alerts: the page polls this, shows any new ones, then marks them delivered.
    Route::get('/poll/notifications', [\App\Http\Controllers\NotificationController::class, 'poll'])->name('poll.notifications');

    // Web Push: browser opts in (works even when closed).
    Route::post('/push/subscribe', [\App\Http\Controllers\NotificationController::class, 'subscribe'])->name('push.subscribe');
    Route::post('/push/unsubscribe', [\App\Http\Controllers\NotificationController::class, 'unsubscribe'])->name('push.unsubscribe');

    // -------- Station board: who is on each machine, breaks & shift changes --------
    Route::get('/stations', [\App\Http\Controllers\StationController::class, 'index'])->name('stations.index');
    // The approved package (mockup, template, job order, production details) —
    // the floor needs to see exactly what the leader signed off before running it.
    Route::get('/orders/{order}/package', [ProductionOrderController::class, 'completeJobOrder'])
        ->whereNumber('order')->name('orders.package');
    Route::post('/stations/start', [\App\Http\Controllers\StationController::class, 'start'])->name('stations.start');
    Route::post('/station-sessions/{stationSession}/end', [\App\Http\Controllers\StationController::class, 'end'])
        ->whereNumber('stationSession')->name('stations.end');

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
    Route::post('/my-tasks/{taskId}/hold', [TaskController::class, 'hold'])->whereNumber('taskId')->name('tasks.hold');
    Route::post('/my-tasks/{taskId}/resume', [TaskController::class, 'resume'])->whereNumber('taskId')->name('tasks.resume');
    Route::post('/my-tasks/{taskId}/submit', [TaskController::class, 'submit'])->whereNumber('taskId')->name('tasks.submit');

    // Submitted work files — access checked per-file in the controller.
    Route::get('/task-files/{file}/download', [TaskController::class, 'downloadFile'])
        ->whereNumber('file')->name('tasks.file.download');
    Route::get('/task-files/{file}/view', [TaskController::class, 'viewFile'])
        ->whereNumber('file')->name('tasks.file.view');

    // Job order client-reference files — access checked per-file in the controller.
    Route::get('/job-order-files/{file}/view', [ProductionOrderController::class, 'viewReferenceFile'])
        ->whereNumber('file')->name('job-order-files.view');
    Route::get('/job-order-files/{file}/download', [ProductionOrderController::class, 'downloadReferenceFile'])
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
        Route::get('/job-orders/{order}/create', [ProductionOrderController::class, 'createJobOrder'])->name('job-orders.create');
        Route::post('/job-orders/{order}', [ProductionOrderController::class, 'storeJobOrder'])->name('job-orders.store');
        Route::get('/job-orders/{order}/edit', [ProductionOrderController::class, 'editJobOrder'])->name('job-orders.edit');
        Route::post('/job-orders/{order}/update', [ProductionOrderController::class, 'updateJobOrder'])->name('job-orders.update');
        Route::get('/job-orders/{order}/production', [ProductionOrderController::class, 'productionJobOrder'])->name('job-orders.production');
        Route::post('/job-orders/{order}/production', [ProductionOrderController::class, 'updateProductionDetails'])->name('job-orders.production.update');
        Route::post('/job-orders/{order}/send', [ProductionOrderController::class, 'sendJobOrderToArtist'])->name('job-orders.send');
        Route::post('/job-orders/{order}/reference', [ProductionOrderController::class, 'uploadReferenceFile'])->name('job-orders.reference');
        Route::post('/job-order-files/{file}/delete', [ProductionOrderController::class, 'deleteReferenceFile'])->whereNumber('file')->name('job-order-files.delete');
        Route::post('/job-order-files/{file}/kind', [ProductionOrderController::class, 'markReferenceKind'])->whereNumber('file')->name('job-order-files.kind');
    });

    // -------- Order intake: Sales (and Super Admin) create orders --------
    Route::middleware('role:sales,super_admin')->group(function () {
        Route::get('/orders/create', [ProductionOrderController::class, 'create'])->name('orders.create');
        Route::post('/orders', [ProductionOrderController::class, 'store'])->name('orders.store');
        Route::get('/orders/{order}/edit', [ProductionOrderController::class, 'edit'])->whereNumber('order')->name('orders.edit');
        Route::post('/orders/{order}', [ProductionOrderController::class, 'update'])->whereNumber('order')->name('orders.update');
        Route::post('/orders/{order}/payment', [ProductionOrderController::class, 'recordPayment'])->name('orders.payment');
        Route::post('/orders/{order}/send-for-layout', [ProductionOrderController::class, 'sendForLayout'])->whereNumber('order')->name('orders.send-for-layout');

        // Client design questionnaire → copy-paste ChatGPT prompt.
        Route::get('/orders/{order}/design-brief', [ProductionOrderController::class, 'designBrief'])->whereNumber('order')->name('orders.design-brief');
        Route::post('/orders/{order}/design-brief', [ProductionOrderController::class, 'saveDesignBrief'])->whereNumber('order')->name('orders.design-brief.save');
        // Reopen the single-use client link for one more submission.
        Route::post('/orders/{order}/design-brief/reopen', [ProductionOrderController::class, 'reopenClientBrief'])->whereNumber('order')->name('orders.design-brief.reopen');

        // Live due-date capacity hint on the order forms.
        Route::get('/order-capacity', [ProductionOrderController::class, 'capacity'])->name('orders.capacity');

        // Client documents: DR (no VAT) / PQ (+12% VAT) — before and after payment.
        Route::get('/orders/{order}/document/{type}', [ProductionOrderController::class, 'document'])
            ->whereNumber('order')->whereIn('type', ['dr', 'pq'])->name('orders.document');
        Route::post('/orders/{order}/document/{type}', [ProductionOrderController::class, 'saveDocument'])
            ->whereNumber('order')->whereIn('type', ['dr', 'pq'])->name('orders.document.save');
        Route::post('/orders/{order}/document/{type}/refresh', [ProductionOrderController::class, 'refreshDocument'])
            ->whereNumber('order')->whereIn('type', ['dr', 'pq'])->name('orders.document.refresh');

        // Contract / payment proof / signed copy placed on the sheet.
        Route::post('/orders/{order}/document/{type}/attach', [ProductionOrderController::class, 'uploadDocumentFile'])
            ->whereNumber('order')->whereIn('type', ['dr', 'pq'])->name('orders.document.attach');
        Route::post('/orders/{order}/document/{type}/attach/{index}/delete', [ProductionOrderController::class, 'deleteDocumentFile'])
            ->whereNumber('order')->whereIn('type', ['dr', 'pq'])->whereNumber('index')->name('orders.document.attach.delete');
        Route::get('/orders/{order}/document/{type}/attach/{index}', [ProductionOrderController::class, 'viewDocumentFile'])
            ->whereNumber('order')->whereIn('type', ['dr', 'pq'])->whereNumber('index')->name('orders.document.attach.view');
        Route::post('/orders/{order}/document/{type}/flatlay', [ProductionOrderController::class, 'uploadDocumentFlatlay'])
            ->whereNumber('order')->whereIn('type', ['dr', 'pq'])->name('orders.document.uploadFlatlay');
        Route::get('/orders/{order}/document/{type}/flatlay', [ProductionOrderController::class, 'viewDocumentFlatlay'])
            ->whereNumber('order')->whereIn('type', ['dr', 'pq'])->name('orders.document.flatlay');
    });

    // -------- Finance: all payments + proof (read-only) --------
    Route::middleware('role:finance,leader,super_admin')->group(function () {
        Route::get('/finance', [FinanceController::class, 'index'])->name('finance.index');
        Route::get('/finance/export', [FinanceController::class, 'export'])->name('finance.export');
        Route::get('/finance/payments/{payment}/proof', [FinanceController::class, 'proof'])
            ->whereNumber('payment')->name('finance.proof');
    });

    // -------- Order viewing + calendar: Sales, Leader, Super Admin --------
    Route::middleware('role:sales,leader,super_admin')->group(function () {
        Route::get('/orders', [ProductionOrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/{order}', [ProductionOrderController::class, 'show'])->whereNumber('order')->name('orders.show');
        Route::get('/orders/{order}/job-order', [ProductionOrderController::class, 'jobOrder'])->whereNumber('order')->name('orders.job-order');
        // The whole package as one document (mockup, template, job order, production details).
        Route::get('/orders/{order}/mockup', [ProductionOrderController::class, 'mockup'])->whereNumber('order')->name('orders.mockup');
        Route::get('/orders/{order}/reference', [ProductionOrderController::class, 'references'])->whereNumber('order')->name('orders.references');
        Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar');
        Route::get('/payments/{payment}/proof', [ProductionOrderController::class, 'proof'])
            ->whereNumber('payment')->name('payments.proof');
    });

    // -------- Production management: Leader / Super Admin --------
    Route::middleware('role:leader,super_admin')->group(function () {
        Route::post('/orders/{order}/status', [ProductionOrderController::class, 'updateStatus'])->name('orders.status');

        Route::get('/approvals', [TaskController::class, 'approvals'])->name('approvals');
        Route::post('/tasks/{task}/assign', [TaskController::class, 'assign'])->name('tasks.assign');
        Route::post('/tasks/{task}/unlock', [TaskController::class, 'unlock'])->name('tasks.unlock');
        Route::post('/tasks/{task}/complete', [TaskController::class, 'forceComplete'])->name('tasks.force-complete');

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
