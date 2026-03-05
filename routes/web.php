<?php

use App\Http\Controllers\MonitorController;
use App\Http\Controllers\PublicStatusController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('monitors.index');
});

Route::middleware(['auth'])->group(function () {
    Route::resource('monitors', MonitorController::class);
});

Route::get('/status/{token}', [PublicStatusController::class, 'show'])->name('public.status');

// Rutas de autenticación simples para el MVP
Route::get('/login', function () {
    if ($user = \App\Models\User::where('email', 'admin@example.com')->first()) {
        auth()->login($user);
        return redirect()->route('monitors.index');
    }
    return "Please implement authentication (e.g. Laravel Breeze) or use a seeder to create a user and login manually.";
})->name('login');

Route::post('/logout', function () {
    auth()->logout();
    return redirect('/');
})->name('logout');
