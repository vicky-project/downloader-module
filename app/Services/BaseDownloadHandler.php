<?php

namespace Modules\Downloader\Services;

use Illuminate\Support\Facades\Http;
use Modules\Downloader\Contracts\DownloadHandlerInterface;

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
				"size" => $headers->get("content-length")
					? (int) $headers->get("content-length")
					: null,
				"mime_type" => $headers->get("content-type"),
				"accept_ranges" => $headers->get("accept-ranges") === "bytes",
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
		if ($headers->get("content-disposition")) {
			if (
				preg_match(
					'/filename="?([^"]+)"?/i',
					$headers->get("content-disposition"),
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

	protected function downloadChunked(
		string $url,
		string $savePath,
		?int $start = null
	): array {
		$headers = [];

		if ($start !== null) {
			$headers["Range"] = "bytes={$start}-";
		}

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

		$body = $response->toPsrResponse()->getBody();
		while (!$body->eof()) {
			$chunk = $body->read($this->chunkSize);
			fwrite($file, $chunk);
			$downloaded += strlen($chunk);

			// Yield progress
			yield $downloaded;
		}

		fclose($file);
		$body->close();

		return [
			"downloaded" => $downloaded,
			"path" => $savePath,
		];
	}

	abstract public function handle(
		string $url,
		string $savePath,
		array $options = []
	): array;
	abstract public function supports(string $url): bool;
}
