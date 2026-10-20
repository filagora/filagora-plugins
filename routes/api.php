<?php

declare(strict_types=1);

use Filagora\Plugins\Http\Controllers\PluginApiController;
use Illuminate\Support\Facades\Route;

Route::get('plugin-types', [PluginApiController::class, 'types'])->name('types');
Route::get('plugins', [PluginApiController::class, 'index'])->name('index');
Route::post('plugins/updates', [PluginApiController::class, 'updates'])->name('updates');
Route::patch('plugins/{slug}', [PluginApiController::class, 'update'])->name('update');
Route::get('plugins/{slug}', [PluginApiController::class, 'show'])->name('show');
Route::get('plugins/{slug}/releases', [PluginApiController::class, 'releases'])->name('releases');
Route::post('plugins/{slug}/releases', [PluginApiController::class, 'storeRelease'])->name('releases.store');
Route::get('plugins/{slug}/releases/{version}', [PluginApiController::class, 'release'])->name('release');
Route::post('plugins/{slug}/releases/{version}/moderate', [PluginApiController::class, 'moderateRelease'])->name('release.moderate');
Route::get('plugins/{slug}/download/{version}', [PluginApiController::class, 'downloadMetadata'])->name('download-metadata');
Route::get('plugins/{slug}/subscription', [PluginApiController::class, 'subscription'])->name('subscription');
Route::post('plugins/{slug}/subscription', [PluginApiController::class, 'subscribe'])->name('subscribe');
Route::delete('plugins/{slug}/subscription', [PluginApiController::class, 'unsubscribe'])->name('unsubscribe');
