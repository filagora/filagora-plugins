<?php

declare(strict_types=1);

use Filagora\Plugins\Http\Controllers\DownloadController;
use Illuminate\Support\Facades\Route;

Route::get('{slug}/download', [DownloadController::class, 'plugin'])->name('filagora-plugins.download');
Route::get('{slug}/releases/{release}/download', [DownloadController::class, 'release'])->whereNumber('release')->name('filagora-plugins.download.release');
