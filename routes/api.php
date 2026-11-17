<?php

declare(strict_types=1);

use Filagora\Plugins\Http\Controllers\PluginApiController;
use Filagora\Plugins\Http\Middleware\CachePublicReads;
use Illuminate\Support\Facades\Route;

// Reads: counted together, and cacheable by shared caches for a guest.
Route::middleware(['throttle:filagora-plugins-api-read', CachePublicReads::class])->group(function (): void {
    Route::get('plugin-types', [PluginApiController::class, 'types'])->name('types');
    Route::get('plugins', [PluginApiController::class, 'index'])->name('index');
    Route::get('plugins/{slug}', [PluginApiController::class, 'show'])->name('show');
    Route::get('plugins/{slug}/releases', [PluginApiController::class, 'releases'])->name('releases');
    Route::get('plugins/{slug}/releases/{version}', [PluginApiController::class, 'release'])->name('release');
    Route::get('plugins/{slug}/download/{version}', [PluginApiController::class, 'downloadMetadata'])->name('download-metadata');
    Route::get('plugins/{slug}/subscription', [PluginApiController::class, 'subscription'])->name('subscription');
});

// An installed-plugin check: a body of plugins, so more work than a read.
Route::post('plugins/updates', [PluginApiController::class, 'updates'])->middleware('throttle:filagora-plugins-api-updates')->name('updates');

// Changes.
Route::middleware('throttle:filagora-plugins-api-write')->group(function (): void {
    Route::patch('plugins/{slug}', [PluginApiController::class, 'update'])->name('update');
    Route::post('plugins/{slug}/releases', [PluginApiController::class, 'storeRelease'])->name('releases.store');
    Route::post('plugins/{slug}/releases/{version}/moderate', [PluginApiController::class, 'moderateRelease'])->name('release.moderate');
    Route::post('plugins/{slug}/subscription', [PluginApiController::class, 'subscribe'])->name('subscribe');
    Route::delete('plugins/{slug}/subscription', [PluginApiController::class, 'unsubscribe'])->name('unsubscribe');
});
