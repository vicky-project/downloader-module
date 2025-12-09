<?php

namespace Modules\Downloader\Services\Strategies;

use GuzzleHttp\Client;
use GuzzleHttp\Promise;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Downloader\Contracts\DownloadStrategyInterface;

class DirectFileStrategy implements DownloadStrategyInterface
{
	protected $client;

	public function __construct()
	{
		$this->client = new Client([
			"timeout" => 0,
			"connect_timeout" => 30,
			"read_timeout" => 300,
			"headers" => [
				"User-Agent" =>
					"Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
			],
		]);
	}

	public function analyze(string $url): array
	{
		try {
			$response = $this->client->head($url, [
				"allow_redirects" => true,
			]);

			$headers = $response->getHeaders();
			$filename = $this->extractFilename($url, $headers);
			$size = $headers["Content-Length"][0] ?? null;
			$acceptsRanges =
				isset($headers["Accept-Ranges"]) &&
				$headers["Accept-Ranges"][0] === "bytes";

			return [
				"success" => true,
				"type" => "direct_file",
				"filename" => $filename,
				"size" => $size ? (int) $size : null,
				"accepts_ranges" => $acceptsRanges,
				"headers" => $headers,
				"strategy" => "direct",
				"supports_chunking" => $acceptsRanges,
				"supports_resume" => $acceptsRanges,
			];
		} catch (\Exception $e) {
			return [
				"success" => false,
				"error" => $e->getMessage(),
				"type" => "direct_file",
				"strategy" => "direct",
			];
		}
	}

	public function download(string $url, array $options = []): array
	{
		$connections = $options["connections"] ?? 4;
		$chunkSize = $options["chunk_size"] ?? 1048576; // 1MB

		// Get file info
		$info = $this->analyze($url);
		if (!$info["success"]) {
			throw new \Exception("Failed to analyze file: " . $info["error"]);
		}

		if ($info["accepts_ranges"] && $info["size"]) {
			return $this->downloadWithChunks(
				$url,
				$info["size"],
				$connections,
				$chunkSize
			);
		} else {
			return $this->downloadSingle($url);
		}
	}

	protected function downloadWithChunks(
		string $url,
		int $size,
		int $connections,
		int $chunkSize
	): array {
		$tempDir = sys_get_temp_dir() . "/downloads/" . uniqid();
		if (!file_exists($tempDir)) {
			mkdir($tempDir, 0755, true);
		}

		$numChunks = ceil($size / $chunkSize);
		$chunksPerConnection = ceil($numChunks / $connections);

		$promises = [];
		$chunkFiles = [];

		for ($i = 0; $i < $connections; $i++) {
			$startChunk = $i * $chunksPerConnection;
			$endChunk = min(($i + 1) * $chunksPerConnection - 1, $numChunks - 1);

			if ($startChunk >= $numChunks) {
				break;
			}

			$promises[] = $this->downloadChunkRange(
				$url,
				$startChunk,
				$endChunk,
				$chunkSize,
				$tempDir
			);
		}

		$results = Promise\Utils::settle($promises)->wait();

		// Merge chunks
		$finalFile = $tempDir . "/final";
		$fp = fopen($finalFile, "w");

		for ($i = 0; $i < $numChunks; $i++) {
			$chunkFile = $tempDir . "/chunk_" . $i;
			if (file_exists($chunkFile)) {
				$chunkFp = fopen($chunkFile, "r");
				stream_copy_to_stream($chunkFp, $fp);
				fclose($chunkFp);
				unlink($chunkFile);
			}
		}

		fclose($fp);

		return [
			"success" => true,
			"temp_file" => $finalFile,
			"temp_dir" => $tempDir,
		];
	}

	protected function downloadChunkRange(
		string $url,
		int $startChunk,
		int $endChunk,
		int $chunkSize,
		string $tempDir
	) {
		return function () use (
			$url,
			$startChunk,
			$endChunk,
			$chunkSize,
			$tempDir
		) {
			for ($chunk = $startChunk; $chunk <= $endChunk; $chunk++) {
				$start = $chunk * $chunkSize;
				$end = min(
					$start + $chunkSize - 1,
					$startChunk + ($endChunk - $startChunk + 1) * $chunkSize - 1
				);

				$chunkFile = $tempDir . "/chunk_" . $chunk;
				$fp = fopen($chunkFile, "w");

				$this->client->get($url, [
					"headers" => ["Range" => "bytes=" . $start . "-" . $end],
					"sink" => $fp,
				]);

				fclose($fp);
			}
		};
	}

	protected function downloadSingle(string $url): array
	{
		$tempFile = tempnam(sys_get_temp_dir(), "download_");

		$this->client->get($url, [
			"sink" => $tempFile,
		]);

		return [
			"success" => true,
			"temp_file" => $tempFile,
		];
	}

	protected function extractFilename(string $url, array $headers): string
	{
		// From Content-Disposition
		if (isset($headers["Content-Disposition"][0])) {
			$disposition = $headers["Content-Disposition"][0];
			if (preg_match('/filename="([^"]+)"/', $disposition, $matches)) {
				return $matches[1];
			} elseif (preg_match("/filename='([^']+)'/", $disposition, $matches)) {
				return $matches[1];
			}
		}

		// From URL
		$path = parse_url($url, PHP_URL_PATH);
		if ($path) {
			$basename = basename($path);
			if (!empty($basename)) {
				return $basename;
			}
		}

		return "download_" . time();
	}

	public function supports(string $url): bool
	{
		$path = parse_url($url, PHP_URL_PATH);
		$extension = pathinfo($path, PATHINFO_EXTENSION);

		// Check if URL looks like a direct file
		return !empty($extension) ||
			Str::contains($url, [
				".pdf",
				".zip",
				".mp4",
				".mp3",
				".exe",
				".dmg",
				".iso",
			]);
	}
}
