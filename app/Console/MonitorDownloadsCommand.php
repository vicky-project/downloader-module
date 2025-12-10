<?php

namespace Modules\Downloader\Console;

use Illuminate\Support\Number;
use Illuminate\Console\Command;
use Modules\Downloader\Models\Download;
use Modules\Downloader\Enums\DownloadStatus;

class MonitorDownloadsCommand extends Command
{
	protected $signature = "downloader:monitor";
	protected $description = "Monitor active downloads";

	public function handle()
	{
		$this->info("Monitoring active downloads...\n");

		while (true) {
			$downloads = Download::whereIn("status", [
				DownloadStatus::DOWNLOADING,
				DownloadStatus::PENDING,
			])
				->orderBy("updated_at", "desc")
				->get();

			if ($downloads->isEmpty()) {
				$this->info("No active downloads.");
			} else {
				$this->table(
					["ID", "Filename", "Progress", "Status", "Speed", "ETA", "Updated"],
					$downloads
						->map(function ($download) {
							return [
								$download->id,
								substr($download->filename, 0, 30),
								$download->progress . "%",
								$download->status,
								$download->speed
									? Number::fileSize($download->speed) . "/s"
									: "N/A",
								$download->time_remaining
									? $this->formatTime($download->time_remaining)
									: "N/A",
								$download->updated_at->diffForHumans(),
							];
						})
						->toArray()
				);
			}

			sleep(2); // Update setiap 2 detik
			$this->output->write("\033[2J\033[;H"); // Clear screen
		}
	}

	private function formatTime($seconds)
	{
		// ... format time
		return $seconds->toISOString();
	}
}
