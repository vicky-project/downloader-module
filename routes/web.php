<?php

use Illuminate\Support\Facades\Route;
use Modules\Downloader\Http\Controllers\DownloaderController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('downloaders', DownloaderController::class)->names('downloader');
});
