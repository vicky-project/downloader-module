<?php

use Illuminate\Support\Facades\Route;
use Modules\Downloader\Http\Controllers\DownloaderController;

Route::middleware(["auth"])->group(function () {
	Route::prefix("downloader")
		->name("downloader.")
		->group(function () {
			Route::get("/", [DownloaderController::class, "index"])->name("index");
		});
});
