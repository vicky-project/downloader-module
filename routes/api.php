<?php

use Illuminate\Support\Facades\Route;
use Modules\Downloader\Http\Controllers\DownloaderController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('downloaders', DownloaderController::class)->names('downloader');
});
