<?php

use App\Http\Controllers\MonitorController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicStatusController;
use App\Http\Controllers\SeoCheckController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('monitors.index');
});

Route::middleware(['auth'])->group(function () {
    Route::resource('monitors', MonitorController::class);
    Route::resource('seo', SeoCheckController::class)->only(['index', 'create', 'store']);
    Route::post('seo/{seoCheck}/recheck', [SeoCheckController::class, 'recheck'])->name('seo.recheck');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::get('/status/{token}', [PublicStatusController::class, 'show'])
    ->name('public.status')
    ->middleware('throttle:30,1');

require __DIR__.'/auth.php';
