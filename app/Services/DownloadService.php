<?php
namespace Modules\Downloader\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Downloader\Models\Download;
use Modules\Downloader\Enums\UrlType;
use Modules\Downloader\Enums\DownloadStatus;
use Modules\Downloader\Jobs\ProcessDownloadJob;
use Modules\Downloader\Contracts\DownloadHandlerInterface;

class DownloadService
{
	/**
	 * Preview file information from URL
	 */
	public function previewFile(string $url): array
	{
		$urlAnalysis = $this->urlResolver->resolve($url);
		$urlType = $urlAnalysis["type"];

		$handler = $this->handlerFactory->getHandlerForType($urlType);
		$validation = $handler->validate($url);
		if (!$validation["valid"]) {
			throw new \Exception($validation["message"] ?? "URL validation failed");
		}

		$filename = $handler->getFilename($url);

		$fileInfo = [];
		$directUrl = $handler->getDirectDownloadUrl($url);
		if ($directUrl) {
			$fileInfo = $handler->getFileInfo($directUrl);
		} elseif ($urlType === UrlType::DIRECT_FILE) {
			$fileInfo = $handler->getFileInfo($url);
		}

		return [
			"filename" => $filename,
			"file_size" => $fileInfo["size"] ?? null,
			"file_type" => $fileInfo["type"] ?? "unknown",
			"content_type" => $fileInfo["content_type"] ?? null,
			"url_analysis" => $urlAnalysis,
			"handler" => $handler->getName(),
			"is_downloadable" => $this->isDownloadable(
				$urlAnalysis,
				$urlType,
				$handler
			),
			"estimated_size" => $this->estimateFileSize(
				$urlAnalysis,
				$urlType,
				$handler
			),
		];
	}

	/**
	 * Start download process
	 */
	public function startDownload(string $url, int $userId): Download
	{
		// Create download job record with URL metadata
		$downloadJob = Download::create([
			"user_id" => $userId,
			"url" => $url,
			"status" => DownloadStatus::PENDING,
			"connections" => config("downloader.connections", 1),
		]);

		logger()->info("Start downloading: " . $downloadJob->job_id);

		// Dispatch job to queue with handler context
		ProcessDownloadJob::dispatch($downloadJob)->onQueue("downloads");

		return $downloadJob;
	}

	/**
	 * Get download progress
	 */
	public function getDownloadProgress(string $jobId, int $userId): ?Download
	{
		return Download::where("job_id", $jobId)
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
		$downloadJob = DownloadJob::where("user_id", $userId);

		return [
			"total_downloads" => $downloadJob->count(),
			"completed_downloads" => $downloadJob
				->where("status", "completed")
				->count(),
			"active_downloads" => $downloadJob
				->whereIn("status", [
					DownloadStatus::PENDING,
					DownloadStatus::DOWNLOADING,
				])
				->count(),
			"total_download_size" => $downloadJob
				->where("status", DownloadStatus::COMPLETED)
				->sum("file_size"),
			"handlers_used" => $downloadJob
				->select("handler")
				->distinct()
				->pluck("handler")
				->toArray(),
		];
	}

	/**
	 * Get supported URL types
	 */
	public function getSupportedUrlTypes(): array
	{
		return $this->handlerFactory->getSupportedTypes();
	}

	/**
	 * Check if URL is downloadable
	 */
	private function isDownloadable(
		array $urlAnalysis,
		UrlType $urlType,
		DownloadHandlerInterface $handler
	): bool {
		// Check if URL type is supported
		if (!$urlAnalysis["is_supported"]) {
			return false;
		}

		// Check if direct download URL is available
		if ($urlAnalysis["direct_download_url"]) {
			return true;
		}

		// Check if handler can handle the download
		$directUrl = $handler->getDirectDownloadUrl($urlAnalysis["url"]);
		return $directUrl !== null || $handler->supports($urlType);
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
	private function getFilenameFromUrl(string $url, UrlType $urlType): string
	{
		switch ($urlType) {
			case UrlType::GOOGLE_DRIVE:
				return $this->getGoogleDriveFilename($url);
			case UrlType::YOUTUBE:
				return $this->getYouTubeFilename($url);
			case UrlType::DROPBOX:
				return $this->getDropboxFilename($url);
			case UrlType::DIRECT_FILE:
				$path = parse_url($url, PHP_URL_PATH);
				$filename = basename($path);
				return $filename ?: "downloaded_file_" . time();
			default:
				$path = parse_url($url, PHP_URL_PATH);
				$filename = basename($path);
				return $filename ?: "downloaded_file_" . time() . "_" . $urlType->value;
		}
	}

	/**
	 * Generate filename for Google Drive URLs
	 */
	private function getGoogleDriveFilename(string $url): string
	{
		// Extract file ID and try to get original filename via API
		// For now, use generic name with timestamp
		$metadata = $this->urlResolver->resolve($url)["metadata"];
		$fileId = $metadata["file_id"] ?? null;

		if ($fileId) {
			return "google_drive_file_{$fileId}_" . time();
		}

		return "google_drive_file_" . time();
	}

	/**
	 * Generate filename for YouTube URLs
	 */
	private function getYouTubeFilename(string $url): string
	{
		$metadata = $this->urlResolver->resolve($url)["metadata"];
		$videoId = $metadata["video_id"] ?? null;

		if ($videoId) {
			// Try to get video title via YouTube API (placeholder)
			// For now, use video ID
			return "youtube_video_{$videoId}_" . time() . ".mp4";
		}

		return "youtube_video_" . time() . ".mp4";
	}

	/**
	 * Generate filename for Dropbox URLs
	 */
	private function getDropboxFilename(string $url): string
	{
		$path = parse_url($url, PHP_URL_PATH);
		$filename = basename($path);

		// Remove query parameters from filename
		$filename = explode("?", $filename)[0];

		return $filename ?: "dropbox_file_" . time();
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
			$client = new \GuzzleHttp\Client([
				"timeout" => 10,
				"connect_timeout" => 10,
				"allow_redirects" => true,
				"headers" => [
					"User-Agent" =>
						"Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
					"Accept" => "*/*",
				],
			]);
			$response = $client->head($url, [
				"on_headers" => function (
					\Psr\Http\Message\ResponseInterface $response
				) {
					if (
						!$response->hasHeader("Content-Length") ||
						$response->getHeaderLine("Content-Length") === 0
					) {
						throw new \Exception("No content length");
					}
				},
			]);

			$headers = $response->getHeaders();

			return [
				"size" => $headers["Content-Length"][0] ?? null,
				"type" => $headers["Content-Type"][0] ?? null,
				"content_type" => $headers["Content-Type"][0] ?? null,
				"last_modified" => $headers["Last-Modified"][0] ?? null,
				"accept_ranges" => $headers["Accept-Ranges"][0] ?? null,
				"etag" => $headers["ETag"][0] ?? null,
			];
		} catch (\GuzzleHttp\Exception\ClientException $e) {
			return [
				"error" => "Client error: " . $e->getResponse()->getStatusCode(),
				"size" => null,
				"type" => null,
			];
		} catch (\GuzzleHttp\Exception\ServerException $e) {
			return [
				"error" => "Server error: " . $e->getResponse()->getStatusCode(),
				"size" => null,
				"type" => null,
			];
		} catch (\GuzzleHttp\Exception\ConnectException $e) {
			return [
				"error" => "Connection failed: " . $e->getMessage(),
				"size" => null,
				"type" => null,
			];
		} catch (\Exception $e) {
			return [
				"error" => "Error: " . $e->getMessage(),
				"size" => null,
				"type" => null,
			];
		}
	}

	/**
	 * Check if special handler exists for URL type
	 */
	private function hasSpecialHandler(UrlType $urlType): bool
	{
		$handlers = [
			UrlType::GOOGLE_DRIVE => config(
				"downloader.handlers.google_drive",
				false
			),
			UrlType::YOUTUBE => config("downloader.handlers.youtube", false),
			UrlType::ONE_DRIVE => config("downloader.handlers.one_drive", false),
		];

		return $handlers[$urlType->name] ?? false;
	}

	/**
	 * Estimate file size based on URL type
	 */
	private function estimateFileSize(
		array $urlAnalysis,
		UrlType $urlType,
		DownloadHandlerInterface $handler
	): ?int {
		// For direct files, try to get actual size
		if ($urlAnalysis["direct_download_url"]) {
			$info = $handler->getFileInfo($urlAnalysis["direct_download_url"]);
			return $info["size"] ?? null;
		}

		// Default estimates based on URL type
		return match ($urlType) {
			UrlType::YOUTUBE => 50 * 1024 * 1024,
			UrlType::GOOGLE_DRIVE => 10 * 1024 * 1024,
			UrlType::DROPBOX => 5 * 1024 * 1024,
			UrlType::ONE_DRIVE => 8 * 1024 * 1024,
			default => null,
		};
	}

	private function getQueueForUrlType(UrlType $urlType): string
	{
		return match ($urlType) {
			UrlType::YOUTUBE => "youtube-downloads",
			UrlType::GOOGLE_DRIVE => "drive-downloads",
			UrlType::ONE_DRIVE => "onedrive-downloads",
			default => "default",
		};
	}
}
