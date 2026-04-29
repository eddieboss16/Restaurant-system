<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\MpesaController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\SessionController;
use App\Models\MenuItem;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

// Daraja STK push callback. Public, no auth -- Safaricom calls this directly.
Route::post('/mpesa/callback', [MpesaController::class, 'callback']);

Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/user', fn (Request $request) => $request->user()->only('id', 'name', 'role'));

    Route::get('/me/today', fn (Request $request, ReportService $reports) => $reports->waiterToday($request->user()));

    Route::get('/menu-items', function () {
        return MenuItem::where('is_available', true)
            ->orderBy('category')
            ->orderBy('name')
            ->get(['id', 'name', 'price', 'category']);
    });

    Route::middleware('role:waiter,manager')->group(function () {
        Route::get('/sessions', [SessionController::class, 'index']);
        Route::post('/sessions', [SessionController::class, 'store']);
        Route::get('/sessions/{session}', [SessionController::class, 'show']);

        Route::post('/sessions/{session}/orders', [OrderController::class, 'store']);
        Route::post('/sessions/{session}/payment', [PaymentController::class, 'store']);
        Route::post('/sessions/{session}/payment/stk', [PaymentController::class, 'initiateStk']);

        Route::delete('/orders/{order}', [OrderController::class, 'cancel']);
    });

    Route::middleware('role:waiter,kitchen,manager')->group(function () {
        Route::patch('/orders/{order}/status', [OrderController::class, 'updateStatus']);
    });

    Route::middleware('role:kitchen,manager')->group(function () {
        Route::get('/kitchen/queue', [OrderController::class, 'kitchenQueue']);
        Route::get('/kitchen/history', [OrderController::class, 'kitchenHistory']);
    });

    // Expenses + reports + low-stock: manager records/views; admin reaches
    // all via the role-wildcard bypass in EnsureRole.
    Route::middleware('role:manager')->group(function () {
        Route::get('/expenses', [ExpenseController::class, 'index']);
        Route::post('/expenses', [ExpenseController::class, 'store']);
        Route::patch('/expenses/{expense}', [ExpenseController::class, 'update']);
        Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy']);

        Route::get('/reports/today', [AdminController::class, 'dailyReport']);
        Route::get('/reports/month', [AdminController::class, 'monthlyReport']);

        Route::get('/inventory/low-stock', fn () => \App\Models\Resource::query()
            ->whereColumn('current_stock', '<=', 'low_stock_threshold')
            ->orderBy('name')
            ->get(['id', 'name', 'unit', 'current_stock', 'low_stock_threshold']));

        Route::get('/paid-sessions', [SessionController::class, 'paidHistory']);
    });

    // Menu + inventory CRUD + cancellation log: manager-and-up. Path keeps
    // the /admin prefix because it's how the existing admin dashboard already
    // calls it -- "admin" here is a path namespace, not an access claim.
    Route::middleware('role:manager')->prefix('admin')->group(function () {
        Route::get('/menu-items', [AdminController::class, 'listMenuItems']);
        Route::post('/menu-items', [AdminController::class, 'createMenuItem']);
        Route::patch('/menu-items/{menuItem}', [AdminController::class, 'updateMenuItem']);
        Route::delete('/menu-items/{menuItem}', [AdminController::class, 'deleteMenuItem']);

        Route::get('/resources', [AdminController::class, 'listResources']);
        Route::patch('/resources/{resource}', [AdminController::class, 'updateResource']);
        Route::post('/resources/{resource}/restock', [AdminController::class, 'restockResource']);

        Route::get('/cancellations', [AdminController::class, 'listCancellations']);
    });

    // Strictly admin: hiring/firing/role changes.
    Route::middleware('role:admin')->prefix('admin')->group(function () {
        Route::get('/staff', [AdminController::class, 'listStaff']);
        Route::post('/staff', [AdminController::class, 'createStaff']);
        Route::patch('/staff/{user}', [AdminController::class, 'updateStaff']);
    });
});
