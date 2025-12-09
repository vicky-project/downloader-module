<?php

namespace Modules\Downloader\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Downloader\Models\Download;
use Modules\Downloader\Enums\DownloadStatus;
use Modules\Downloader\Services\ChunkDownloader;
use Modules\Downloader\Services\EnhancedDownloadManager;

class ProcessDownloadJob implements ShouldQueue
{
	use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

	public $tries = 3;
	public $timeout = 7200; // 2 hour
	public $maxExceptions = 3;
	public $backoff = [60, 300, 600];

	protected $download;

	public function __construct(Download $download)
	{
		$this->download = $download;
	}

	public function handle()
	{
		$downloadManager = new EnhancedDownloadManager();
		$downloadManager->processDownload($this->download);

		// $this->download->update(["status" => "processing"]);

		// $downloader = new ChunkDownloader();
		// $downloader->download($this->download);

		$this->release();
	}

	public function failed(\Throwable $exception)
	{
		$this->download->update([
			"status" => DownloadStatus::FAILED,
			"error_message" => $exception->getMessage(),
		]);

		logger()->error("Download job failed: " . $exception->getMessage(), [
			"job_id" => $this->download->job_id,
			"url" => $this->download->url,
			"exception" => $exception->getTraceAsString(),
		]);
	}
}
