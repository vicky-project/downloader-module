<?php

namespace Modules\Downloader\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Downloader\Models\Download;
use Modules\Downloader\Services\DownloadManager;
use Modules\Downloader\Events\DownloadProgress;
use Modules\Downloader\Enums\DownloadStatus;
use Illuminate\Support\Facades\Storage;

class ProcessDownloadJob implements ShouldQueue
{
	use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

	public $timeout = 0; // No timeout for large downloads
	public $tries = 3;
	public $backoff = [60, 300, 600]; // Retry delays in seconds

	protected $download;
	protected $downloadManager;

	public function __construct(Download $download)
	{
		$this->download = $download;
		$this->downloadManager = new DownloadManager();
	}

	public function handle()
	{
		// Update job ID
		$this->download->update([
			"job_id" => $this->job->getJobId(),
			"status" => DownloadStatus::DOWNLOADING,
			"started_at" => now(),
		]);

		try {
			$handler = $this->downloadManager->getHandler($this->download->url);

			// Prepare save path
			$userFolder = "downloads/user_{$this->download->user_id}";
			$filename = $this->download->filename;
			$tempPath = "{$userFolder}/temp_{$filename}";
			$finalPath = "{$userFolder}/{$filename}";

			// Ensure directory exists
			Storage::makeDirectory($userFolder);

			$options = [];

			// Handle resume if applicable
			if (
				$this->download->isResumable() &&
				$this->download->downloaded_size > 0
			) {
				$options["resume_from"] = $this->download->downloaded_size;
			}

			// Start download
			$result = $handler->handle(
				$this->download->url,
				Storage::path($tempPath),
				$options
			);

			// Process progress updates
			foreach ($result as $progressData) {
				if ($this->download->status === DownloadStatus::PENDING) {
					$this->release(60); // Release job for 60 seconds
					break;
				}

				if ($this->download->fresh()->status === DownloadStatus::CANCELLED) {
					$this->cleanup($tempPath);
					return;
				}

				$this->updateProgress($progressData, $tempPath);
			}

			// Complete download
			if (!isset($progressData["completed"]) || $progressData["completed"]) {
				$this->completeDownload($tempPath, $finalPath);
			}
		} catch (\Exception $e) {
			$this->download->update([
				"status" => DownloadStatus::FAILED,
				"error_message" => $e->getMessage(),
			]);

			throw $e; // Allow job retry
		}
	}

	protected function updateProgress(array $data, string $tempPath)
	{
		$updates = [
			"downloaded_size" => $data["downloaded"] ?? 0,
			"progress" => $data["progress"] ?? 0,
		];

		if (isset($data["total"])) {
			$updates["total_size"] = $data["total"];
		}

		// Calculate speed and ETA
		if ($this->download->started_at) {
			$elapsed = now()->diffInSeconds($this->download->started_at);
			if ($elapsed > 0) {
				$updates["speed"] = $updates["downloaded_size"] / $elapsed;

				if ($updates["speed"] > 0 && isset($data["total"])) {
					$remaining = $data["total"] - $updates["downloaded_size"];
					$updates["time_remaining"] = $remaining / $updates["speed"];
				}
			}
		}

		$this->download->update($updates);

		// Broadcast progress event
		event(
			new DownloadProgress(
				$this->download->job_id,
				$updates["progress"],
				$updates["downloaded_size"],
				$updates["total_size"] ?? null
			)
		);
	}

	protected function completeDownload(string $tempPath, string $finalPath)
	{
		// Move from temp to final location
		Storage::move($tempPath, $finalPath);

		$this->download->update([
			"status" => DownloadStatus::COMPLETED,
			"progress" => 100,
			"file_path" => $finalPath,
			"completed_at" => now(),
			"downloaded_size" => $this->download->total_size,
		]);

		event(
			new DownloadProgress(
				$this->download->job_id,
				100,
				$this->download->total_size,
				$this->download->total_size
			)
		);
	}

	protected function cleanup(string $tempPath)
	{
		if (Storage::exists($tempPath)) {
			Storage::delete($tempPath);
		}
	}

	public function failed(\Throwable $exception)
	{
		$this->download->update([
			"status" => DownloadStatus::FAILED,
			"error_message" => $exception->getMessage(),
		]);
	}
}
