<?php

use App\Http\Controllers\AlertController;
use App\Http\Controllers\MonitorController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicOverviewController;
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
    Route::get('/alerts', [AlertController::class, 'index'])->name('alerts.index');
    Route::post('/alerts/check-now', [AlertController::class, 'checkNow'])
        ->name('alerts.check-now')
        ->middleware('throttle:5,1');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/profile/notifications', [ProfileController::class, 'updateNotifications'])->name('profile.notifications.update');
    Route::post('/profile/notifications/test', [ProfileController::class, 'sendTestNotification'])
        ->name('profile.notifications.test')
        ->middleware('throttle:5,1');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::get('/status/{token}', [PublicStatusController::class, 'show'])
    ->name('public.status')
    ->middleware('throttle:30,1');

// Separate from /status/{token} above: that page is per-monitor and opt-in
// via the monitor's own token, this one is global — a single site-wide token
// gates a page listing every monitor currently down plus the tracked
// technology alerts. See PublicOverviewController for the 404-on-empty-token
// guard.
Route::get('/overview/{token}', [PublicOverviewController::class, 'show'])
    ->name('public.overview')
    ->middleware('throttle:30,1');

// T8: open (no token, no login) counterpart to /overview/{token} above —
// distinct literal path, so it cannot collide with the {token} segment
// route regardless of declaration order. Renders the alerts in full but
// only a down-monitor COUNT, never a monitor's name or URL — see
// PublicOverviewController::open().
Route::get('/overview', [PublicOverviewController::class, 'open'])
    ->name('public.overview.open')
    ->middleware('throttle:30,1');

require __DIR__.'/auth.php';
