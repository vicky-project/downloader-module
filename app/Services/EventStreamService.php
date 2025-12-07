<?php
namespace Modules\Downloader\Services;

use Illuminate\Http\StreamedEvent;
use Illuminate\Support\Facades\Auth;
use Modules\Downloader\Models\DownloadJob;
use Modules\Downloader\Enums\DownloadStatus;

class EventStreamService
{
	/**
	 * Generate EventStream response for active downloads
	 */
	public function streamActiveDownloads(int $jobId)
	{
		return response()->eventStream(
			function () use ($jobId) {
				$lastProgress = null;
				$lastStatus = null;

				// Send initial connection event
				yield $this->sendEvent("connected", [
					"timestamp" => now()->toISOString(),
					"message" => "EventStream connected",
				]);

				for ($i = 0; $i < 1800; $i++) {
					if (connection_aborted()) {
						break;
					}

					$download = DownloadJob::where("job_id", $jobId)->first();

					if (!$download) {
						// No active downloads, send keep-alive and continue
						yield $this->sendEvent("not-found", [
							"message" => "Job not found",
						]);
						break;
					}

					if (
						$download->progress !== $lastProgress ||
						$download->status !== $lastStatus
					) {
						$eventData = [
							"job_id" => $jobId,
							"status" => $download->status,
							"progress" => $download->progress,
							"filename" => $download->original_filename,
							"file_size" => $download->file_size,
							"speed" => $this->calculateDownloadSpeed($download),
							"eta" => $this->calculateETA($download),
							"updated_at" => $download->updated_at->toISOString(),
						];

						if ($download->status === DownloadStatus::COMPLETED) {
							$eventData["download_url"] = route("api.downloader.file", [
								"job_id" => $download->job_id,
							]);
							yield $this->sendEvent("completed", $eventData);
							break;
						} else {
							yield $this->sendEvent("progress", $eventData);
						}

						$lastProgress = $download->progress;
						$lastStatus = $download->status;

						if (
							in_array($download->status, [
								DownloadStatus::FAILED,
								DownloadStatus::CANCELLED,
							])
						) {
							break;
						}
					}

					sleep(1);
				}

				// Send disconnect event
				yield $this->sendEvent("disconnected", [
					"timestamp" => now()->toISOString(),
					"message" => "EventStream disconnected",
				]);
			},
			[
				"Content-Type" => "text/event-stream",
				"Cache-Control" => "no-cache",
				"Connection" => "keep-alive",
				"X-Accel-Buffering" => "no", // Disable nginx buffering
				"Access-Control-Allow-Origin" => "*",
				"Access-Control-Allow-Headers" => "Cache-Control",
			]
		);
	}

	/**
	 * Send SSE event
	 */
	private function sendEvent(string $event, array $data): StreamedEvent
	{
		return new StreamedEvent(event: $event, data: json_encode($data));
	}

	/**
	 * Determine if we should send update for this download
	 */
	private function shouldSendUpdate(
		DownloadJob $download,
		array $processedJobs
	): bool {
		$jobId = $download->job_id;

		// Always send update for new jobs
		if (!isset($processedJobs[$jobId])) {
			return true;
		}

		$lastUpdate = $processedJobs[$jobId];

		// Send update if status changed
		if ($lastUpdate["status"] !== $download->status) {
			return true;
		}

		// Send update if progress changed by more than 1%
		if ($download->status === DownloadStatus::DOWNLOADING) {
			$progressDiff = abs($download->progress - ($lastUpdate["progress"] ?? 0));
			if ($progressDiff >= 1) {
				// Send update for every 1% change
				return true;
			}
		}

		// Send update if it's been more than 10 seconds since last update
		$timeSinceLastUpdate = now()->timestamp - $lastUpdate["timestamp"];
		if ($timeSinceLastUpdate > 10) {
			return true;
		}

		return false;
	}

	/**
	 * Calculate download speed (KB/s)
	 */
	private function calculateDownloadSpeed(DownloadJob $download): ?float
	{
		$metadata = $download->metadata ?? [];

		if (isset($metadata["speed_history"])) {
			$history = $metadata["speed_history"];
			if (is_array($history) && count($history) > 0) {
				return round(array_sum($history) / count($history), 2);
			}
		}

		// Estimate based on progress and time
		$startTime = isset($metadata["started_at"])
			? strtotime($metadata["started_at"])
			: $download->created_at->timestamp;

		$elapsedTime = time() - $startTime;

		if ($elapsedTime > 0 && $download->file_size && $download->progress > 0) {
			$downloadedBytes = ($download->file_size * $download->progress) / 100;
			return round($downloadedBytes / $elapsedTime / 1024, 2); // KB/s
		}

		return null;
	}

	/**
	 * Calculate estimated time remaining (seconds)
	 */
	private function calculateETA(DownloadJob $download): ?int
	{
		if ($download->progress <= 0 || $download->progress >= 100) {
			return null;
		}

		$speed = $this->calculateDownloadSpeed($download);
		if (!$speed || $speed <= 0 || !$download->file_size) {
			return null;
		}

		$remainingBytes =
			($download->file_size * (100 - $download->progress)) / 100;
		$remainingKB = $remainingBytes / 1024;

		return (int) ceil($remainingKB / $speed);
	}

	/**
	 * Clean up old processed jobs from memory
	 */
	private function cleanupProcessedJobs(array &$processedJobs): void
	{
		$oneHourAgo = now()->timestamp - 3600;

		foreach ($processedJobs as $jobId => $data) {
			if ($data["timestamp"] < $oneHourAgo) {
				unset($processedJobs[$jobId]);
			}
		}
	}

	/**
	 * Get single download progress stream
	 */
	public function streamSingleDownload(string $jobId, int $userId)
	{
		return response()->StreamedEvent(
			function () use ($jobId, $userId) {
				$maxExecutionTime = 3600; // 1 hour
				$startTime = time();

				yield $this->sendEvent("connected", [
					"job_id" => $jobId,
					"timestamp" => now()->toISOString(),
				]);

				$lastProgress = 0;

				while (true) {
					if (connection_aborted()) {
						break;
					}

					if (time() - $startTime > $maxExecutionTime) {
						yield $this->sendEvent("timeout", ["message" => "Stream timeout"]);
						break;
					}

					$download = DownloadJob::where("job_id", $jobId)
						->where("user_id", $userId)
						->first();

					if (!$download) {
						yield $this->sendEvent("not_found", [
							"message" => "Download job not found",
						]);
						sleep(5);
						continue;
					}

					// Send update if progress changed
					if (
						$download->progress != $lastProgress ||
						$download->status != DownloadStatus::DOWNLOADING
					) {
						$eventData = [
							"job_id" => $download->job_id,
							"status" => $download->status,
							"progress" => $download->progress,
							"filename" => $download->original_filename,
							"file_size" => $download->file_size,
							"updated_at" => $download->updated_at->toISOString(),
						];

						if ($download->status === DownloadStatus::COMPLETED) {
							$eventData["download_url"] = route(
								"download.file",
								$download->job_id
							);
							yield $this->sendEvent("completed", $eventData);
							break; // Stop stream when completed
						} elseif ($download->status === DownloadStatus::FAILED) {
							$eventData["error_message"] = $download->error_message;
							yield $this->sendEvent("failed", $eventData);
							break; // Stop stream when failed
						} else {
							yield $this->sendEvent("progress", $eventData);
						}

						$lastProgress = $download->progress;
					}

					// Send keep-alive every 30 seconds
					if (time() % 30 === 0) {
						yield $this->sendEvent("keep-alive", [
							"timestamp" => now()->toISOString(),
						]);
					}

					sleep(1); // Check every second
				}

				yield $this->sendEvent("disconnected", [
					"timestamp" => now()->toISOString(),
				]);
			},
			[
				"Content-Type" => "text/event-stream",
				"Cache-Control" => "no-cache",
				"Connection" => "keep-alive",
				"X-Accel-Buffering" => "no",
				"Access-Control-Allow-Origin" => "*",
				"Access-Control-Allow-Headers" => "Cache-Control",
			]
		);
	}
}
