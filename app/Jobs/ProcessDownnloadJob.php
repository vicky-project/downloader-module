<?php

namespace Modules\Downloader\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Downloader\Models\Download;
use Modules\Downloader\Services\ChunkDownloader;

class ProcessDownloadJob implements ShouldQueue
{
	use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

	public $tries = 3;
	public $timeout = 3600; // 1 hour
	public $maxExceptions = 3;

	protected $download;

	public function __construct(Download $download)
	{
		$this->download = $download;
	}

	public function handle()
	{
		$this->download->update(["status" => "processing"]);

		$downloader = new ChunkDownloader();
		$downloader->download($this->download);
	}

	public function failed(\Throwable $exception)
	{
		$this->download->update([
			"status" => "failed",
			"error_message" => $exception->getMessage(),
		]);
	}
}
