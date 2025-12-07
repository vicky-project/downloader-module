<?php

use Illuminate\Support\Facades\Route;
use Modules\Downloader\Http\Controllers\DownloaderController;

Route::middleware(["auth"])->group(function () {
	Route::prefix("admin")->group(function () {
		Route::prefix("downloader")
			->name("downloader.")
			->group(function () {
				Route::get("/", [DownloaderController::class, "index"])->name("index");
				Route::post("download", [
					DownloaderController::class,
					"startDownload",
				])->name("download");
			});
	});
});
