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
				$lastId = 0;

				while (true) {
					if (connection_aborted()) {
						break;
					}
					$download = Download::where("job_id", $jobId)->first();

					if ($download) {
						$data = [
							"job_id" => $download->job_id,
							"status" => $download->status,
							"progress" => $download->progress,
							"downloaded_size" => $download->downloaded_size,
							"formatted_size" => Number::fileSize($download->downloaded_size),
							"file_size" => $download->file_size,
							"formatted_file_size" => Number::fileSize($download->file_size),
							"download_speed" => $download->download_speed,
							"filename" => $download->filename,
							"updated_at" => $download->updated_at->toISOString(),
						];

						yield $this->sentEvent("progress", $data);

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
					}

					sleep(1);
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
