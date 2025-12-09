<?php

namespace Modules\Downloader\Services\Strategies;

use GuzzleHttp\Client;
use Illuminate\Support\Str;
use Modules\Downloader\Contracts\DownloadStrategyInterface;

class GenericStrategy implements DownloadStrategyInterface
{
	protected $client;

	public function __construct()
	{
		$this->client = new Client([
			"timeout" => 30,
			"connect_timeout" => 10,
			"headers" => [
				"User-Agent" =>
					"Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
			],
		]);
	}

	public function analyze(string $url): array
	{
		try {
			// Try to get information about the URL
			$response = $this->client->head($url, [
				"allow_redirects" => true,
			]);

			$headers = $response->getHeaders();
			$contentType = $headers["Content-Type"][0] ?? "application/octet-stream";
			$contentDisposition = $headers["Content-Disposition"][0] ?? null;

			// Determine if it's a downloadable resource
			$isDownloadable = $this->isDownloadable(
				$contentType,
				$contentDisposition
			);

			$filename = $this->extractFilename($url, $headers);
			$size = $headers["Content-Length"][0] ?? null;
			$acceptsRanges =
				isset($headers["Accept-Ranges"]) &&
				$headers["Accept-Ranges"][0] === "bytes";

			return [
				"success" => true,
				"type" => "generic",
				"filename" => $filename,
				"size" => $size ? (int) $size : null,
				"content_type" => $contentType,
				"is_downloadable" => $isDownloadable,
				"accepts_ranges" => $acceptsRanges,
				"strategy" => $isDownloadable ? "direct" : "adaptive",
				"supports_chunking" => $acceptsRanges,
				"supports_resume" => $acceptsRanges,
			];
		} catch (\Exception $e) {
			return [
				"success" => false,
				"error" => $e->getMessage(),
				"type" => "generic",
			];
		}
	}

	public function download(string $url, array $options = []): array
	{
		$info = $this->analyze($url);

		if (!$info["success"]) {
			throw new \Exception("Failed to analyze URL: " . $info["error"]);
		}

		if ($info["is_downloadable"] && $info["accepts_ranges"]) {
			// Use direct file strategy
			$directStrategy = new DirectFileStrategy();
			return $directStrategy->download($url, $options);
		} else {
			// Use adaptive download strategy
			return $this->adaptiveDownload($url, $info, $options);
		}
	}

	protected function adaptiveDownload(
		string $url,
		array $info,
		array $options
	): array {
		// For non-direct downloads, we need to read the response stream
		$tempFile = tempnam(sys_get_temp_dir(), "adaptive_");

		$response = $this->client->get($url, [
			"stream" => true,
			"progress" => function ($downloadTotal, $downloadedBytes) {
				// Track progress if needed
			},
		]);

		$body = $response->getBody();
		$fp = fopen($tempFile, "w");

		while (!$body->eof()) {
			fwrite($fp, $body->read(8192));
		}

		fclose($fp);

		return [
			"success" => true,
			"temp_file" => $tempFile,
			"filename" => $info["filename"],
			"size" => filesize($tempFile),
		];
	}

	protected function isDownloadable(
		string $contentType,
		?string $contentDisposition
	): bool {
		// Common downloadable content types
		$downloadableTypes = [
			"application/octet-stream",
			"application/pdf",
			"application/zip",
			"application/x-rar-compressed",
			"application/x-7z-compressed",
			"application/x-tar",
			"application/x-gzip",
			"application/vnd.ms-excel",
			"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
			"application/msword",
			"application/vnd.openxmlformats-officedocument.wordprocessingml.document",
			"application/vnd.ms-powerpoint",
			"application/vnd.openxmlformats-officedocument.presentationml.presentation",
			"image/jpeg",
			"image/png",
			"image/gif",
			"image/webp",
			"image/svg+xml",
			"audio/mpeg",
			"audio/wav",
			"audio/ogg",
			"video/mp4",
			"video/webm",
			"video/ogg",
			"text/plain",
			"text/csv",
			"text/html",
			"application/json",
			"application/xml",
		];

		// Check if content type is downloadable
		if (in_array(explode(";", $contentType)[0], $downloadableTypes)) {
			return true;
		}

		// Check if content disposition indicates download
		if (
			$contentDisposition &&
			Str::contains($contentDisposition, "attachment")
		) {
			return true;
		}

		return false;
	}

	protected function extractFilename(string $url, array $headers): string
	{
		// Try Content-Disposition first
		if (isset($headers["Content-Disposition"][0])) {
			$disposition = $headers["Content-Disposition"][0];
			if (preg_match('/filename="([^"]+)"/', $disposition, $matches)) {
				return $matches[1];
			} elseif (preg_match("/filename='([^']+)'/", $disposition, $matches)) {
				return $matches[1];
			} elseif (preg_match("/filename=([^\s;]+)/", $disposition, $matches)) {
				return $matches[1];
			}
		}

		// Try to extract from URL
		$path = parse_url($url, PHP_URL_PATH);
		if ($path) {
			$basename = basename($path);
			if (!empty($basename) && !Str::contains($basename, ["?", "=", "&"])) {
				return $basename;
			}
		}

		// Generate based on content type
		$contentType = $headers["Content-Type"][0] ?? "application/octet-stream";
		$extension = $this->getExtensionFromContentType($contentType);

		return "download_" . time() . "." . $extension;
	}

	protected function getExtensionFromContentType(string $contentType): string
	{
		$mimeMap = [
			"application/pdf" => "pdf",
			"application/zip" => "zip",
			"application/x-rar-compressed" => "rar",
			"application/x-7z-compressed" => "7z",
			"image/jpeg" => "jpg",
			"image/png" => "png",
			"image/gif" => "gif",
			"image/webp" => "webp",
			"audio/mpeg" => "mp3",
			"audio/wav" => "wav",
			"audio/ogg" => "ogg",
			"video/mp4" => "mp4",
			"video/webm" => "webm",
			"video/ogg" => "ogv",
			"text/plain" => "txt",
			"text/html" => "html",
			"text/csv" => "csv",
			"application/json" => "json",
			"application/xml" => "xml",
		];

		$mime = explode(";", $contentType)[0];
		return $mimeMap[$mime] ?? "bin";
	}

	public function supports(string $url): bool
	{
		// Generic strategy supports all URLs as fallback
		return true;
	}
}
