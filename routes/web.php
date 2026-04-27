<?php

use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (! Auth::check()) {
        return redirect('/login');
    }

    return redirect(match (Auth::user()->role) {
        'admin' => '/admin/dashboard',
        'kitchen' => '/kitchen/dashboard',
        default => '/waiter/dashboard',
    });
});

Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'login']);
Route::post('/logout', [LoginController::class, 'logout'])->middleware('auth');

Route::middleware('auth')->group(function () {
    Route::view('/waiter/dashboard', 'waiter.dashboard')
        ->middleware('role:waiter,manager,admin')
        ->name('waiter.dashboard');

    Route::view('/kitchen/dashboard', 'kitchen.dashboard')
        ->middleware('role:kitchen,manager,admin')
        ->name('kitchen.dashboard');

    Route::view('/admin/dashboard', 'admin.dashboard')
        ->middleware('role:admin')
        ->name('admin.dashboard');
});
