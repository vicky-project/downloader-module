<?php

use Illuminate\Support\Facades\Route;
use Modules\Downloader\Http\Controllers\DownloaderController;

Route::prefix("v1")->group(function () {
	Route::prefix("downloaders")
		->name("downloader.")
		->group(function () {
			Route::get("preview", [
				DownloaderController::class,
				"previewDownload",
			])->name("preview");

			Route::get("active", [
				DownloaderController::class,
				"getActiveDownloads",
			])->name("active");
			Route::get("stream/{job_id}", [
				DownloaderController::class,
				"stream",
			])->name("stream");
			Route::get("file/{job_id}", [DownloaderController::class, "file"])->name(
				"file"
			);
		});
});
