<?php

namespace Modules\Downloader\Services;

use Modules\DownloadManager\Models\Download;
use Modules\DownloadManager\Models\DownloadQueue;
use Modules\Downloader\Services\Strategies\GenericStrategy;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DownloadManager
{
	protected $urlTypeDetector;
	protected $strategies = [];

	public function __construct()
	{
		$this->urlTypeDetector = new URLTypeDetector();
	}

	public function processDownload(Download $job)
	{
		try {
			$job->update([
				"status" => "analyzing",
				"started_at" => now(),
			]);

			// Detect URL type and get strategy
			$detection = $this->urlTypeDetector->detect($job->url);

			if (!$detection["success"]) {
				throw new \Exception(
					"Failed to detect URL type: " . $detection["error"]
				);
			}

			$strategy = $detection["strategy_instance"];

			// Get detailed analysis
			$analysis = $strategy->analyze($job->url);

			if (!$analysis["success"]) {
				throw new \Exception("Analysis failed: " . $analysis["error"]);
			}

			// Update job with analysis results
			$job->update([
				"status" => "processing",
				"filename" => $analysis["filename"] ?? null,
				"file_size" => $analysis["size"] ?? null,
				"mime_type" =>
					$analysis["mime_type"] ?? ($analysis["content_type"] ?? null),
				"file_extension" =>
					pathinfo($analysis["filename"] ?? "", PATHINFO_EXTENSION) ?: null,
				"metadata" => array_merge($job->metadata ?? [], [
					"url_type" => $detection["type"],
					"strategy" => $detection["strategy"],
					"analysis" => $analysis,
					"supports_chunking" => $analysis["supports_chunking"] ?? false,
					"supports_resume" => $analysis["supports_resume"] ?? false,
				]),
			]);

			// Execute download based on strategy
			$this->executeDownload($job, $strategy, $analysis);
		} catch (\Exception $e) {
			$job->update([
				"status" => "failed",
				"error_message" => $e->getMessage(),
			]);
			Log::error("Download processing failed: " . $e->getMessage(), [
				"job_id" => $job->id,
				"url" => $job->url,
			]);
		}
	}

	protected function executeDownload(Download $job, $strategy, array $analysis)
	{
		$job->update(["status" => "downloading"]);

		$options = [
			"connections" => $job->connections,
			"chunk_size" => $this->calculateChunkSize($analysis["size"] ?? 0),
			"job_id" => $job->id,
		];

		try {
			// Execute strategy-specific download
			$result = $strategy->download($job->url, $options);

			if (!$result["success"]) {
				throw new \Exception(
					"Download failed: " . ($result["error"] ?? "Unknown error")
				);
			}

			// Move downloaded file to final location
			$finalPath = $this->moveToFinalLocation($result, $job, $analysis);

			$job->update([
				"status" => "completed",
				"progress" => 100,
				"downloaded_size" => $analysis["size"] ?? filesize($finalPath),
				"save_path" => $finalPath,
				"completed_at" => now(),
			]);

			// Cleanup temporary files
			$this->cleanupTempFiles($result);
		} catch (\Exception $e) {
			// Check if we can resume
			if ($analysis["supports_resume"] ?? false) {
				$job->update([
					"status" => "paused",
					"error_message" =>
						"Download paused due to error: " . $e->getMessage(),
				]);
			} else {
				throw $e;
			}
		}
	}

	protected function calculateChunkSize(?int $fileSize): int
	{
		if (!$fileSize) {
			return 1048576; // 1MB default
		}

		if ($fileSize > 1073741824) {
			// > 1GB
			return 10485760; // 10MB
		} elseif ($fileSize > 104857600) {
			// > 100MB
			return 5242880; // 5MB
		} else {
			return 1048576; // 1MB
		}
	}

	protected function moveToFinalLocation(
		array $result,
		Download $job,
		array $analysis
	): string {
		$sourceFile = $result["temp_file"];
		$filename =
			$job->filename ?: $analysis["filename"] ?? "download_" . $job->id;

		// Ensure downloads directory exists
		$downloadsDir = storage_path("app/downloads");
		if (!file_exists($downloadsDir)) {
			mkdir($downloadsDir, 0755, true);
		}

		$finalPath = $downloadsDir . "/" . $filename;

		// Handle duplicate filenames
		$counter = 1;
		while (file_exists($finalPath)) {
			$info = pathinfo($filename);
			$finalPath =
				$downloadsDir .
				"/" .
				$info["filename"] .
				"_" .
				$counter .
				"." .
				($info["extension"] ?? "");
			$counter++;
		}

		rename($sourceFile, $finalPath);

		return $finalPath;
	}

	protected function cleanupTempFiles(array $result)
	{
		if (isset($result["temp_file"]) && file_exists($result["temp_file"])) {
			@unlink($result["temp_file"]);
		}

		if (isset($result["temp_dir"]) && file_exists($result["temp_dir"])) {
			$this->deleteDirectory($result["temp_dir"]);
		}
	}

	protected function deleteDirectory(string $dir): bool
	{
		if (!file_exists($dir)) {
			return true;
		}

		$files = array_diff(scandir($dir), [".", ".."]);
		foreach ($files as $file) {
			$path = $dir . "/" . $file;
			is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
		}

		return rmdir($dir);
	}

	public function resumeDownload(Download $job)
	{
		$metadata = $job->metadata ?? [];

		if (!($metadata["supports_resume"] ?? false)) {
			throw new \Exception("This download does not support resume");
		}

		// Get the strategy
		$strategyClass = $metadata["strategy"] ?? GenericStrategy::class;
		$strategy = new $strategyClass();

		// Resume download
		$this->executeDownload($job, $strategy, $metadata["analysis"] ?? []);
	}

	public function getSupportedPlatforms(): array
	{
		return [
			"Direct Files" => [
				"description" => "Files with direct download links",
				"extensions" => [
					".pdf",
					".zip",
					".mp4",
					".mp3",
					".exe",
					".dmg",
					".iso",
				],
				"supports_chunking" => true,
				"supports_resume" => true,
			],
			"Google Drive" => [
				"description" => "Google Drive sharing links",
				"patterns" => ["drive.google.com", "docs.google.com"],
				"supports_chunking" => false,
				"supports_resume" => true,
				"notes" => "Large files may require confirmation",
			],
			"Dropbox" => [
				"description" => "Dropbox sharing links",
				"patterns" => ["dropbox.com", "dropboxusercontent.com"],
				"supports_chunking" => true,
				"supports_resume" => true,
			],
			"OneDrive" => [
				"description" => "Microsoft OneDrive sharing links",
				"patterns" => ["onedrive.live.com", "1drv.ms", "sharepoint.com"],
				"supports_chunking" => true,
				"supports_resume" => true,
			],
			"Streaming Sites" => [
				"description" => "YouTube, Vimeo, Dailymotion, etc.",
				"patterns" => [
					"youtube.com",
					"youtu.be",
					"vimeo.com",
					"dailymotion.com",
				],
				"requires_tool" => "yt-dlp or youtube-dl",
				"supports_chunking" => false,
				"supports_resume" => false,
			],
			"Generic URLs" => [
				"description" => "Any other URL that returns downloadable content",
				"supports_chunking" => "Depends on server",
				"supports_resume" => "Depends on server",
			],
		];
	}
}
