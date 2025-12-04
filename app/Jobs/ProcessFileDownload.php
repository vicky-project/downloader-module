<?php
namespace Modules\Downloader\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Modules\Downloader\Models\DownloadJob;
use Modules\Downloader\Enums\DownloadStatus;
use Modules\Downloader\Services\GoogleDriveService;

class ProcessFileDownload implements ShouldQueue
{
	use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

	public $downloadJob;
	public $timeout = 3600;
	public $tries = 3;

	public function __construct(DownloadJob $downloadJob)
	{
		$this->downloadJob = $downloadJob;
		$this->onQueue("downloads");
	}

	public function handle()
	{
		$this->downloadJob->update([
			"status" => DownloadStatus::DOWNLOADING,
			"metadata" => array_merge($this->downloadJob->metadata ?? [], [
				"job_started_at" => now()->toISOString(),
			]),
		]);

		try {
			$url = $this->downloadJob->url;

			// Check URL type and process accordingly
			if ($this->isGoogleDriveUrl($url)) {
				$this->downloadFromGoogleDrive($url);
			} else {
				$this->downloadRegularFile($url);
			}

			$this->downloadJob->update([
				"status" => DownloadStatus::COMPLETED,
				"progress" => 100,
				"metadata" => array_merge($this->downloadJob->metadata ?? [], [
					"completed_at" => now()->toISOString(),
				]),
			]);
		} catch (\Exception $e) {
			$this->downloadJob->update([
				"status" => DownloadStatus::FAILED,
				"error_message" => $e->getMessage(),
				"metadata" => array_merge($this->downloadJob->metadata ?? [], [
					"failed_at" => now()->toISOString(),
					"error" => $e->getMessage(),
				]),
			]);

			if ($this->attempts() < $this->tries) {
				$this->release(60);
			}
		}
	}

	private function isGoogleDriveUrl($url): bool
	{
		return str_contains($url, "drive.google.com");
	}

	private function downloadRegularFile($url)
	{
		$response = Http::timeout(300)
			->withOptions([
				"progress" => function ($downloadTotal, $downloadedBytes) {
					if ($downloadTotal > 0) {
						$progress = ($downloadedBytes / $downloadTotal) * 100;
						$this->downloadJob->update(["progress" => $progress]);
					}
				},
			])
			->get($url);

		if ($response->successful()) {
			$filename = $this->downloadJob->filename;
			$fileContent = $response->body();

			// Store with user-specific folder structure
			$localPath = "downloads/" . $this->downloadJob->user_id . "/" . $filename;
			Storage::disk("local")->put($localPath, $fileContent);

			$this->downloadJob->update([
				"local_path" => $localPath,
				"file_size" => Storage::disk("local")->size($localPath),
			]);
		} else {
			throw new \Exception("Failed to download file: " . $response->status());
		}
	}

	private function downloadFromGoogleDrive($url)
	{
		$googleDriveService = new GoogleDriveService();
		$fileId = $googleDriveService->extractFileId($url);

		if (!$fileId) {
			throw new \Exception("Invalid Google Drive URL");
		}

		$accessToken = $googleDriveService->getAccessToken();
		if (!$accessToken) {
			throw new \Exception("Failed to get Google Drive access token");
		}

		// Get file info for original name
		$fileInfo = $googleDriveService->getFileInfo($fileId, $accessToken);
		$originalName = $fileInfo["name"] ?? $this->downloadJob->original_filename;

		// Download file content
		$fileContent = $googleDriveService->downloadFile($fileId, $accessToken);
		if ($fileContent === null) {
			throw new \Exception("Failed to download file from Google Drive");
		}

		// Save file
		$localPath =
			"downloads/" .
			$this->downloadJob->user_id .
			"/" .
			$this->downloadJob->filename;
		Storage::disk("local")->put($localPath, $fileContent);

		$this->downloadJob->update([
			"original_filename" => $originalName,
			"local_path" => $localPath,
			"file_size" => Storage::disk("local")->size($localPath),
		]);
	}

	public function failed(\Throwable $exception)
	{
		$this->downloadJob->update([
			"status" => DownloadStatus::FAILED,
			"error_message" => "Job failed: " . $exception->getMessage(),
		]);
	}
}
