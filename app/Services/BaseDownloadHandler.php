<?php

namespace Modules\Downloader\Services;

use Illuminate\Support\Facades\Http;
use Modules\Downloader\Contracts\DownloadHandlerInterface;
use Generator;

abstract class BaseDownloadHandler implements DownloadHandlerInterface
{
	protected $chunkSize = 1024 * 1024; // 1MB chunks
	protected $maxRetries = 3;
	protected $timeout = 30;

	public function getInfo(string $url): array
	{
		try {
			$response = Http::withOptions([
				"allow_redirects" => true,
				"timeout" => 10,
			])->head($url);

			$headers = $response->headers();

			return [
				"size" => isset($headers["content-length"])
					? (int) $headers["content-length"]
					: null,
				"mime_type" => $headers["content-type"],
				"accept_ranges" => $headers["accept-ranges"] === "bytes",
				"filename" => $this->extractFilename($url, $headers),
			];
		} catch (\Exception $e) {
			return [
				"size" => null,
				"mime_type" => null,
				"accept_ranges" => false,
				"filename" => basename(parse_url($url, PHP_URL_PATH)),
			];
		}
	}

	protected function extractFilename(string $url, $headers): string
	{
		// Try to get filename from Content-Disposition header
		if (isset($headers["content-disposition"])) {
			if (
				preg_match(
					'/filename="?([^"]+)"?/i',
					$headers["content-disposition"],
					$matches
				)
			) {
				return $matches[1];
			}
		}

		// Extract from URL
		$path = parse_url($url, PHP_URL_PATH);
		$filename = basename($path);

		// Remove query parameters
		$filename = strtok($filename, "?");

		return $filename ?: "download_" . time();
	}

	/**
	 * Download file in chunks and yield progress
	 *
	 * @param string $url URL to download
	 * @param string $savePath Path to save the file
	 * @param int|null $start Byte position to start from (for resume)
	 * @return Generator Yields downloaded bytes count
	 */
	protected function downloadChunked(
		string $url,
		string $savePath,
		?int $start = null
	): Generator {
		$headers = [];

		if ($start !== null) {
			$headers["Range"] = "bytes={$start}-";
		}

		logger()->info("Start chunked download.", [
			"url" => $url,
			"save_path" => $savePath,
			"start_byte" => $start,
			"chunk_size" => $this->chunkSize,
		]);

		try {
			$response = Http::withOptions([
				"stream" => true,
				"timeout" => $this->timeout,
				"headers" => $headers,
			])->get($url);

			if (!$response->successful()) {
				throw new \Exception(
					"Failed to download from URL: " . $response->status()
				);
			}

			$file = fopen($savePath, $start !== null ? "ab" : "wb");
			$downloaded = $start ?? 0;
			$chunkCount = 0;

			$body = $response->toPsrResponse()->getBody();
			while (!$body->eof()) {
				$chunk = $body->read($this->chunkSize);
				$chunkSizeBytes = strlen($chunk);

				if ($chunkSizeBytes === 0) {
					usleep(100000);
					continue;
				}

				fwrite($file, $chunk);
				$downloaded += $chunkSizeBytes;
				$chunkCount++;

				logger()->debug("Chunk downloaded", [
					"chunk_number" => $chunkCount,
					"chunk_size" => $chunkSizeBytes,
					"total_downloaded" => $downloaded,
					"eof" => $body->eof(),
				]);

				// Yield progress
				yield $downloaded;
			}

			fclose($file);
			$body->close();

			logger()->info("Chunked download completed", [
				"total_chunks" => $chunkCount,
				"total_downloaded" => $downloaded,
			]);
		} catch (\Exception $e) {
			logger()->error("Error in chunked download.", [
				"error" => $e->getMessage(),
				"url" => $url,
			]);

			throw $e;
		}
	}

	/**
	 * Validate URL format
	 */
	protected function isValidUrl($url): bool
	{
		$pattern =
			'/^(https?:\/\/)?([\da-z\.-]+)\.([a-z\.]{2,6})([\/\w \.-]*)*\/?$/';
		return preg_match($pattern, $url) && filter_var($url, FILTER_VALIDATE_URL);
	}

	/**
	 * Get content type from URL
	 */
	protected function getContentTypeFromUrl($url)
	{
		try {
			$ch = curl_init($url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_NOBODY, true);
			curl_setopt($ch, CURLOPT_TIMEOUT, 5);
			curl_exec($ch);

			$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
			curl_close($ch);

			return $contentType;
		} catch (\Exception $e) {
			return null;
		}
	}

	abstract public function handle(
		string $url,
		string $savePath,
		array $options = []
	): Generator;
	abstract public function supports(string $url): bool;
}
