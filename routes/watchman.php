<?php

use Illuminate\Support\Facades\Route;
use Watchman\Laravel\Http\Controllers\IngestController;
use Watchman\Laravel\Http\Controllers\RefreshController;
use Watchman\Laravel\Http\Controllers\SearchController;

$cfg = config('watchman.routes', []);

Route::prefix($cfg['prefix'] ?? 'watchman')
    ->middleware($cfg['middleware'] ?? ['api'])
    ->name('watchman.')
    ->group(function () {
        Route::get('v2/search', [SearchController::class, 'search'])->name('search');
        Route::get('v2/listinfo', [SearchController::class, 'listInfo'])->name('listinfo');
        Route::get('v2/data/refresh', [RefreshController::class, 'status'])->name('refresh.status');
        Route::get('ping', fn () => response('PONG', 200, ['Content-Type' => 'text/plain']))->name('ping');
    });

// Admin routes change data, so they need real protection: they are only registered when
// admin_middleware contains an authentication or authorization middleware, recognised by name
// (auth, auth:sanctum, auth.basic, can:…, or a class whose name contains "Auth", e.g. Authenticate).
$adminMiddleware = (array) ($cfg['admin_middleware'] ?? ['api']);
$adminProtected = \Watchman\Laravel\WatchmanServiceProvider::hasAuthMiddleware($adminMiddleware);

if (!empty($cfg['admin_enabled']) && !$adminProtected && app()->runningInConsole()) {
    // Logged from console runs (route:list, deploys) rather than on every web request.
    \Illuminate\Support\Facades\Log::warning('watchman: admin routes are enabled but watchman.routes.admin_middleware has no authentication middleware; they were not registered.');
}

if (!empty($cfg['admin_enabled']) && $adminProtected) {
    Route::prefix($cfg['prefix'] ?? 'watchman')
        ->middleware($adminMiddleware)
        ->name('watchman.')
        ->group(function () {
            Route::post('v2/data/refresh', [RefreshController::class, 'refresh'])->name('refresh');
            Route::post('v2/ingest/{fileType}', [IngestController::class, 'ingest'])->name('ingest');
        });
}
