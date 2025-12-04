<?php
namespace Modules\Downloader\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Downloader\Models\DownloadJob;
use Modules\Downloader\Enums\DownloadStatus;
use Modules\Downloader\Jobs\ProcessFileDownload;

class DownloadService
{
	/**
	 * Preview file information from URL
	 */
	public function previewFile(string $url): array
	{
		$filename = $this->getFilenameFromUrl($url);
		$fileInfo = $this->getRemoteFileInfo($url);

		return [
			"filename" => $filename,
			"file_size" => $fileInfo["size"] ?? null,
			"file_type" => $fileInfo["type"] ?? "unknown",
			"content_type" => $fileInfo["content_type"] ?? null,
		];
	}

	/**
	 * Start download process
	 */
	public function startDownload(string $url, int $userId): DownloadJob
	{
		$filename = $this->getFilenameFromUrl($url);
		$safeFilename = $this->generateSafeFilename($filename);

		// Create download job record
		$downloadJob = DownloadJob::create([
			"user_id" => $userId,
			"job_id" => Str::uuid(),
			"url" => $url,
			"filename" => $safeFilename,
			"original_filename" => $filename,
			"file_type" => pathinfo($filename, PATHINFO_EXTENSION),
			"status" => DownloadStatus::PENDING,
			"metadata" => [
				"user_agent" => request()->userAgent(),
				"ip_address" => request()->ip(),
				"started_at" => now()->toISOString(),
			],
		]);

		// Dispatch job to queue
		ProcessFileDownload::dispatch($downloadJob);

		return $downloadJob;
	}

	/**
	 * Get download progress
	 */
	public function getDownloadProgress(string $jobId, int $userId): ?DownloadJob
	{
		return DownloadJob::where("job_id", $jobId)
			->where("user_id", $userId)
			->first();
	}

	/**
	 * Get user's download history
	 */
	public function getDownloadHistory(int $userId, int $perPage = 15)
	{
		return DownloadJob::where("user_id", $userId)
			->orderBy("created_at", "desc")
			->paginate($perPage);
	}

	/**
	 * Cancel a download
	 */
	public function cancelDownload(string $jobId, int $userId): bool
	{
		$downloadJob = DownloadJob::where("job_id", $jobId)
			->where("user_id", $userId)
			->whereIn("status", [
				DownloadStatus::PENDING,
				DownloadStatus::DOWNLOADING,
			])
			->first();

		if (!$downloadJob) {
			return false;
		}

		$downloadJob->update([
			"status" => DownloadStatus::CANCELLED,
			"error_message" => "Cancelled by user",
		]);

		return true;
	}

	/**
	 * Get user download statistics
	 */
	public function getUserStats(int $userId): array
	{
		return [
			"total_downloads" => DownloadJob::where("user_id", $userId)->count(),
			"completed_downloads" => DownloadJob::where("user_id", $userId)
				->where("status", "completed")
				->count(),
			"active_downloads" => DownloadJob::where("user_id", $userId)
				->whereIn("status", ["pending", "downloading"])
				->count(),
			"total_download_size" => DownloadJob::where("user_id", $userId)
				->where("status", "completed")
				->sum("file_size"),
		];
	}

	/**
	 * Download file from URL (for direct download, not queued)
	 */
	public function downloadFileDirect(string $url, string $filename): array
	{
		try {
			$response = Http::timeout(300)
				->withOptions([
					"progress" => function ($downloadTotal, $downloadedBytes) {
						// Progress callback if needed
					},
				])
				->get($url);

			if ($response->successful()) {
				$fileContent = $response->body();
				$localPath = "temp_downloads/" . uniqid() . "_" . $filename;

				Storage::disk("local")->put($localPath, $fileContent);

				return [
					"success" => true,
					"path" => $localPath,
					"size" => Storage::disk("local")->size($localPath),
				];
			}

			return [
				"success" => false,
				"error" => "Failed to download file: " . $response->status(),
			];
		} catch (\Exception $e) {
			return [
				"success" => false,
				"error" => $e->getMessage(),
			];
		}
	}

	/**
	 * Helper: Get filename from URL
	 */
	private function getFilenameFromUrl(string $url): string
	{
		$path = parse_url($url, PHP_URL_PATH);
		$filename = basename($path);

		return $filename ?: "downloaded_file_" . time();
	}

	/**
	 * Helper: Generate safe filename
	 */
	private function generateSafeFilename(string $filename): string
	{
		$safeName = preg_replace("/[^a-zA-Z0-9\._-]/", "_", $filename);
		$name = pathinfo($safeName, PATHINFO_FILENAME);
		$extension = pathinfo($safeName, PATHINFO_EXTENSION);

		return $name . "_" . time() . "." . $extension;
	}

	/**
	 * Helper: Get remote file info
	 */
	private function getRemoteFileInfo(string $url): array
	{
		try {
			$client = new \GuzzleHttp\Client();
			$response = $client->head($url, [
				"timeout" => 10,
				"allow_redirects" => true,
			]);

			return [
				"size" => $response->getHeaderLine("Content-Length"),
				"type" => $response->getHeaderLine("Content-Type"),
				"content_type" => $response->getHeaderLine("Content-Type"),
			];
		} catch (\Exception $e) {
			return [];
		}
	}
}
