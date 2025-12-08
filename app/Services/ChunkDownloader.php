<?php

namespace Modules\Downloader\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Promise;
use GuzzleHttp\Promise\EachPromise;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Modules\Downloader\Models\Download;
use Modules\Downloader\Models\DownloadQueue;

class ChunkDownloader
{
	protected $client;
	protected $maxConnections = 4;
	protected $chunkSize = 1048576; // 1MB

	public function __construct()
	{
		$this->client = new Client([
			"timeout" => 0, // No timeout for large downloads
			"connect_timeout" => 30,
			"read_timeout" => 300,
			"headers" => [
				"User-Agent" =>
					"Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
			],
		]);
	}

	public function download(Download $job)
	{
		try {
			$job->update([
				"status" => "downloading",
				"started_at" => now(),
			]);

			// Analisis URL untuk mendapatkan informasi
			$processor = new UrlProcessor();
			$analysis = $processor->analyzeUrl($job->url);

			if (!$analysis["success"]) {
				throw new \Exception($analysis["error"]);
			}

			// Update job dengan informasi file
			$job->update([
				"filename" => $analysis["filename"],
				"file_size" => $analysis["file_size"],
				"mime_type" => $analysis["mime_type"],
				"file_extension" => $analysis["extension"],
				"metadata" => $analysis,
			]);

			// Tentukan strategi download
			if ($analysis["accepts_ranges"] && $analysis["file_size"]) {
				// Multi-connection download dengan chunking
				$this->downloadWithChunks($job, $analysis);
			} else {
				// Single connection download
				$this->downloadSingle($job, $analysis);
			}
		} catch (\Exception $e) {
			$job->update([
				"status" => "failed",
				"error_message" => $e->getMessage(),
			]);
			Log::error("Download failed: " . $e->getMessage(), [
				"job_id" => $job->id,
				"url" => $job->url,
			]);
		}
	}

	private function downloadWithChunks(Download $job, array $analysis)
	{
		$fileSize = $analysis["file_size"];
		$chunkSize = $this->calculateOptimalChunkSize($fileSize);
		$numChunks = ceil($fileSize / $chunkSize);

		// Buat queue untuk setiap chunk
		$queues = [];
		for ($i = 0; $i < $numChunks; $i++) {
			$start = $i * $chunkSize;
			$end = min($start + $chunkSize - 1, $fileSize - 1);

			$queue = DownloadQueue::create([
				"download_job_id" => $job->id,
				"chunk_index" => $i,
				"start_byte" => $start,
				"end_byte" => $end,
				"temp_file_path" => $this->getTempFilePath($job, $i),
			]);

			$queues[] = $queue;
		}

		// Download chunks secara paralel
		$promises = [];
		foreach ($queues as $queue) {
			$promises[] = function () use ($queue, $job) {
				return $this->downloadChunk($queue, $job);
			};
		}

		$eachPromise = new EachPromise($promises, [
			"concurrency" => min($this->maxConnections, count($queues)),
			"fulfilled" => function ($result, $index) use ($job) {
				$this->updateProgress($job);
			},
			"rejected" => function ($reason, $index) use ($job) {
				Log::error("Chunk download failed: " . $reason);
				$this->updateProgress($job);
			},
		]);

		$eachPromise->promise()->wait();

		// Merge semua chunks
		$this->mergeChunks($job, $queues);

		$job->update([
			"status" => "completed",
			"progress" => 100,
			"downloaded_size" => $fileSize,
			"completed_at" => now(),
		]);
	}

	private function downloadChunk(DownloadQueue $queue, Download $job)
	{
		try {
			$queue->update(["status" => "downloading"]);

			$tempFile = fopen($queue->temp_file_path, "w+");

			$response = $this->client->get($job->url, [
				"headers" => [
					"Range" => "bytes=" . $queue->start_byte . "-" . $queue->end_byte,
				],
				"sink" => $tempFile,
				"progress" => function ($downloadTotal, $downloadedBytes) use ($queue) {
					$queue->update(["downloaded_bytes" => $downloadedBytes]);
				},
			]);

			fclose($tempFile);

			$queue->update([
				"status" => "completed",
				"downloaded_bytes" => $queue->end_byte - $queue->start_byte + 1,
			]);
		} catch (\Exception $e) {
			$queue->update([
				"status" => "failed",
			]);
			throw $e;
		}
	}

	private function mergeChunks(Download $job, array $queues)
	{
		$finalPath = storage_path("app/downloads/" . $job->filename);

		$finalFile = fopen($finalPath, "w+");

		foreach ($queues as $queue) {
			if (file_exists($queue->temp_file_path)) {
				$chunkFile = fopen($queue->temp_file_path, "r");
				stream_copy_to_stream($chunkFile, $finalFile);
				fclose($chunkFile);

				// Hapus file chunk
				unlink($queue->temp_file_path);
			}
		}

		fclose($finalFile);

		$job->update(["save_path" => $finalPath]);
	}

	private function downloadSingle(Download $job, array $analysis)
	{
		$tempPath = $this->getTempFilePath($job, 0);

		$tempFile = fopen($tempPath, "w+");

		$downloaded = 0;
		$lastUpdate = time();

		$response = $this->client->get($job->url, [
			"sink" => $tempFile,
			"progress" => function ($downloadTotal, $downloadedBytes) use (
				$job,
				&$downloaded,
				&$lastUpdate
			) {
				$downloaded = $downloadedBytes;

				// Update progress setiap 1 detik
				if (time() - $lastUpdate >= 1) {
					$progress =
						$downloadTotal > 0 ? ($downloadedBytes / $downloadTotal) * 100 : 0;
					$job->update([
						"downloaded_size" => $downloadedBytes,
						"progress" => round($progress, 2),
					]);
					$lastUpdate = time();
				}
			},
		]);

		fclose($tempFile);

		// Pindahkan ke lokasi final
		$finalPath = storage_path("app/downloads/" . $job->filename);
		rename($tempPath, $finalPath);

		$job->update([
			"status" => "completed",
			"progress" => 100,
			"downloaded_size" => $downloaded,
			"save_path" => $finalPath,
			"completed_at" => now(),
		]);
	}

	private function calculateOptimalChunkSize(int $fileSize): int
	{
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

	private function getTempFilePath(Download $job, int $index): string
	{
		$tempDir = storage_path("app/temp/downloads/" . $job->job_id);
		if (!file_exists($tempDir)) {
			mkdir($tempDir, 0755, true);
		}

		return $tempDir . "/chunk_" . $index . ".part";
	}

	private function updateProgress(Download $job)
	{
		$totalSize = $job->file_size;
		$downloaded = $job->queues()->sum("downloaded_bytes");

		if ($totalSize > 0) {
			$progress = ($downloaded / $totalSize) * 100;
			$job->update([
				"downloaded_size" => $downloaded,
				"progress" => round($progress, 2),
			]);
		}
	}
}
