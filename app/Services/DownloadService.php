<?php
namespace Modules\Downloader\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Downloader\Models\DownloadJob;
use Modules\Downloader\Enums\DownloadStatus;
use Modules\Downloader\Enums\UrlType;
use Modules\Downloader\Jobs\ProcessFileDownload;
use Modules\Downloader\Services\UrlResolverService;

class DownloadService
{
	protected UrlResolverService $urlResolver;

	public function __construct(UrlResolverService $urlResolver)
	{
		$this->urlResolver = $urlResolver;
	}

	/**
	 * Preview file information from URL
	 */
	public function previewFile(string $url): array
	{
		$urlAnalysis = $this->urlResolver->resolve($url);
		$filename = $this->getFilenameFromUrl($url, $urlAnalysis["type"]);

		$fileinfo = [];
		if ($urlAnalysis["direct_download_url"]) {
			$fileinfo = $this->getRemoteFileInfo($urlAnalysis["direct_download_url"]);
		} elseif ($urlAnalysis["type"] === UrlType::DIRECT_FILE) {
			$fileinfo = $this->getRemoteFileInfo($url);
		}

		return [
			"filename" => $filename,
			"file_size" => $fileinfo["size"] ?? null,
			"file_type" => $fileinfo["type"] ?? "unknown",
			"content_type" => $fileinfo["content_type"] ?? null,
			"url_analysis" => $urlAnalysis,
			"is_downloadable" => $this->isDownloadable($urlAnalysis),
			"estimated_size" => $this->estimateFileSize($urlAnalysis),
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
	 * Check if URL is downloadable
	 */
	private function isDownloadable(array $urlAnalysis): bool
	{
		// Check if URL type is supported
		if (!$urlAnalysis["is_supported"]) {
			return false;
		}

		// Check if direct download URL is available
		if ($urlAnalysis["direct_download_url"]) {
			return true;
		}

		// Check if special handling is available
		if ($urlAnalysis["requires_special_handling"]) {
			return $this->hasSpecialHandler($urlAnalysis["type"]);
		}

		return false;
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
	private function estimateFileSize(array $urlAnalysis): ?int
	{
		// For direct files, try to get actual size
		if ($urlAnalysis["direct_download_url"]) {
			$info = $this->getRemoteFileInfo($urlAnalysis["direct_download_url"]);
			return $info["size"] ?? null;
		}

		// Default estimates based on URL type
		return match ($urlAnalysis["type"]) {
			UrlType::YOUTUBE => 50 * 1024 * 1024,
			UrlType::GOOGLE_DRIVE => 10 * 1024 * 1024,
			UrlType::DROPBOX => 5 * 1024 * 1024,
			default => null,
		};
	}
}
