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
		});
});
