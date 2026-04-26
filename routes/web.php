<?php

use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/waiter/dashboard');
});

Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'login']);
Route::post('/logout', [LoginController::class, 'logout'])->middleware('auth');

Route::middleware('auth')->group(function () {
    Route::view('/waiter/dashboard', 'waiter.dashboard')->name('waiter.dashboard');
    Route::view('/admin/dashboard', 'admin.dashboard')->name('admin.dashboard');
});
