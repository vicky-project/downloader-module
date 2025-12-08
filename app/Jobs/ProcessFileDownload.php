<?php
namespace Modules\Downloader\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Downloader\Models\DownloadJob;
use Modules\Downloader\Enums\DownloadStatus;
use Modules\Downloader\Services\DownloadHandlerFactory;

class ProcessFileDownload implements ShouldQueue
{
	use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

	public $downloadJob;
	public $handlerName;
	public $timeout = 3600;
	public $tries = 3;

	public function __construct(DownloadJob $downloadJob, string $handlerName)
	{
		$this->downloadJob = $downloadJob;
		$this->handlerName = $handlerName;
		$this->onQueue("downloads");
	}

	public function handle(DownloadHandlerFactory $factory)
	{
		$this->updateJobStatus(DownloadStatus::DOWNLOADING, [
			"job_started_at" => now()->toISOString(),
			"handler_name" => $this->handlerName,
		]);

		try {
			$handler = $factory->getHandlerByName($this->handlerName);
			if (!$handler) {
				throw new \Exception("Handler '{$this->handlerName}' not found");
			}

			$handler->download(
				$this->downloadJob,
				fn(
					$progress,
					$downloadedBytes,
					$totalBytes
				) => $this->updateDownloadProgress(
					$progress,
					$downloadedBytes,
					$totalBytes
				)
			);

			$this->updateJobStatus(DownloadStatus::COMPLETED, [
				"completed_at" => now()->toISOString(),
			]);
		} catch (\Exception $e) {
			$this->updateJobStatus(
				DownloadStatus::FAILED,
				[
					"failed_at" => now()->toISOString(),
					"error" => $e->getMessage(),
					"handler_error" => true,
				],
				$e->getMessage()
			);

			if ($this->attempts() < $this->tries) {
				$this->release(60);
			}
		}
	}

	public function failed(\Throwable $exception)
	{
		$this->updateJobStatus(
			DownloadStatus::FAILED,
			[
				"job_failed_at" => now()->toISOString(),
				"job_exception" => $exception->getMessage(),
			],
			"Job failed: " . $exception->getMessage()
		);
	}

	private function updateJobStatus(
		string $status,
		array $metadata = [],
		?string $errorMessage = null
	): void {
		$updateData = [
			"status" => $status,
			"metadata" => array_merge($this->downloadJob->metadata ?? [], $metadata),
		];

		if ($errorMessage) {
			$updateData["error_message"] = $errorMessage;
		}

		if ($status === DownloadStatus::COMPLETED) {
			$updateData["progress"] = 100;
		}

		$this->downloadJob->update($updateData);
	}

	private function updateDownloadProgress(
		float $progress,
		int $downloadedBytes,
		int $totalBytes
	): void {
		$currentTime = now()->timestamp;
		$metadata = $this->downloadJob->metadata ?? [];

		if (isset($metadata["last_progress_update"])) {
			$lastTime = $metadata["last_progress_update"]["timestamp"];
			$lastBytes = $metadata["last_progress_update"]["downloaded_bytes"];

			$timeDiff = $currentTime - $lastTime;
			$bytesDiff = $downloadedBytes - $lastBytes;

			if ($timeDiff > 0) {
				$speedKbps = round($bytesDiff / $timeDiff / 1024, 2);

				$speedHistory = $metadata["speed_history"] ?? [];
				$speedHistory[] = $speedKbps;

				if (count($speedHistory) > 10) {
					array_shift($speedHistory);
				}

				$metadata["speed_history"] = $speedHistory;
				$metadata["current_speed"] = $speedKbps;
			}
		}

		$metadata["last_progress_update"] = [
			"timestamp" => $currentTime,
			"progress" => $progress,
			"downloaded_bytes" => $downloadedBytes,
			"total_bytes" => $totalBytes,
		];

		$this->downloadJob->update([
			"progress" => $progress,
			"file_size" => $totalBytes,
			"metadata" => $metadata,
		]);
	}
}
