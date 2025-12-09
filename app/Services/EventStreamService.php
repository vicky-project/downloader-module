<?php
namespace Modules\Downloader\Services;

use Illuminate\Support\Number;
use Illuminate\Http\StreamedEvent;
use Modules\Downloader\Enums\DownloadStatus;
use Modules\Downloader\Models\Download;

class EventStreamService
{
	public function streaming(int $jobId)
	{
		return response()->eventStream(
			function () use ($jobId) {
				$lastProgress = 0;
				$lastUpdate = time();

				while (true) {
					if (connection_aborted()) {
						break;
					}
					$download = Download::where("job_id", $jobId)->first();

					if ($download) {
						$activeStatuses = [
							DownloadStatus::PROCESSING,
							DownloadStatus::DOWNLOADING,
							DownloadStatus::PAUSED,
						];

						if (in_array($download->status, $activeStatuses)) {
							$metadata = $download->metadata ?? [];
							$data = [
								"job_id" => $download->job_id,
								"status" => $download->status,
								"progress" => $download->progress,
								"downloaded_size" => $download->downloaded_size,
								"formatted_size" => Number::fileSize(
									$download->downloaded_size ?? 0
								),
								"file_size" => $download->file_size,
								"formatted_file_size" => Number::fileSize(
									$download->file_size ?? 0
								),
								"download_speed" => $download->download_speed,
								"filename" => $download->filename,
								"connections" => $download->connections,
								"updated_at" => $download->updated_at->toISOString(),
							];

							yield $this->sentEvent("progress", $data);
						}

						if (
							in_array($download->status, [
								DownloadStatus::COMPLETED,
								DownloadStatus::FAILED,
								DownloadStatus::CANCELLED,
							])
						) {
							yield $this->sentEvent("completed", [
								"status" => $download->status,
							]);

							ob_flush();
							flush();
							break;
						}
					} else {
						yield $this->sentEvent("error", [
							"error" => "Download job not found.",
						]);

						ob_flush();
						flush();
						break;
					}

					if (
						isset($download) &&
						in_array($download->status, [
							DownloadStatus::DOWNLOADING,
							DownloadStatus::PROCESSING,
						])
					) {
						if (time() - $lastUpdate >= 5) {
							yield $this->sentEvent("metadata", [
								"active_connections" => $download
									->queues()
									->where("status", DownloadStatus::DOWNLOADING)
									->count(),
								"completed_chunks" => $download
									->queues()
									->where("status", DownloadStatus::COMPLETED)
									->count(),
								"total_chunks" => $download->queues()->count(),
							]);

							ob_flush();
							flush();
							$lastUpdate = time();
						}
					}

					usleep(500000); // 0.5 detik
				}
			},
			[
				"Content-Type" => "text/event-stream",
				"Cache-Control" => "no-cache",
				"X-Accel-Buffering" => "no",
				"Connection" => "keep-alive",
			]
		);
	}

	private function sentEvent(string $event, array $data)
	{
		return new StreamedEvent(event: $event, data: $data);
	}
}
