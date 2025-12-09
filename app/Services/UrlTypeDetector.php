<?php

namespace Modules\Downloader\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Str;
use Modules\Downloader\Contracts\DownloadStrategyInterface;

class URLTypeDetector
{
	protected $client;
	protected $strategies = [];

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

		// Register strategies
		$this->registerStrategies();
	}

	protected function registerStrategies()
	{
		$this->strategies = [
			new \Modules\Downloader\Services\Strategies\DirectFileStrategy(),
			new \Modules\Downloader\Services\Strategies\GoogleDriveStrategy(),
			new \Modules\Downloader\Services\Strategies\DropboxStrategy(),
			new \Modules\Downloader\Services\Strategies\OneDriveStrategy(),
			new \Modules\Downloader\Services\Strategies\StreamingStrategy(),
			new \Modules\Downloader\Services\Strategies\GenericStrategy(),
		];
	}

	public function detect(string $url): array
	{
		try {
			// First, try to detect by URL pattern
			$type = $this->detectByPattern($url);

			// Then verify with HEAD request
			$info = $this->analyzeUrl($url);

			// Determine the best strategy
			$strategy = $this->getStrategy($url, $info);

			return [
				"success" => true,
				"type" => $type,
				"strategy" => get_class($strategy),
				"info" => $info,
				"strategy_instance" => $strategy,
			];
		} catch (\Exception $e) {
			return [
				"success" => false,
				"error" => "Failed to detect URL type: " . $e->getMessage(),
				"url" => $url,
			];
		}
	}

	protected function detectByPattern(string $url): string
	{
		$parsedUrl = parse_url($url);
		$host = strtolower($parsedUrl["host"] ?? "");
		$path = $parsedUrl["path"] ?? "";

		// Google Drive
		if (
			Str::contains($host, "drive.google.com") ||
			Str::contains($path, "/drive/") ||
			Str::contains($url, "google.com/drive")
		) {
			return "google_drive";
		}

		// Dropbox
		if (
			Str::contains($host, "dropbox.com") ||
			Str::contains($url, "dl.dropboxusercontent.com")
		) {
			return "dropbox";
		}

		// OneDrive
		if (
			Str::contains($host, "onedrive.live.com") ||
			Str::contains($host, "1drv.ms")
		) {
			return "onedrive";
		}

		// YouTube and video streaming
		if (
			Str::contains($host, "youtube.com") ||
			Str::contains($host, "youtu.be") ||
			Str::contains($host, "vimeo.com") ||
			Str::contains($host, "dailymotion.com")
		) {
			return "streaming";
		}

		// Direct file with extension
		$extension = pathinfo($path, PATHINFO_EXTENSION);
		if (!empty($extension) && strlen($extension) <= 10) {
			return "direct_file";
		}

		return "generic";
	}

	protected function analyzeUrl(string $url): array
	{
		try {
			// Try HEAD request first
			$response = $this->client->head($url, [
				"allow_redirects" => true,
				"headers" => [
					"Range" => "bytes=0-0", // Just get headers
				],
			]);

			$headers = $response->getHeaders();

			return [
				"headers" => $headers,
				"status" => $response->getStatusCode(),
				"content_type" =>
					$headers["Content-Type"][0] ?? "application/octet-stream",
				"content_length" => $headers["Content-Length"][0] ?? null,
				"accept_ranges" =>
					isset($headers["Accept-Ranges"]) &&
					$headers["Accept-Ranges"][0] === "bytes",
				"content_disposition" => $headers["Content-Disposition"][0] ?? null,
				"is_attachment" =>
					isset($headers["Content-Disposition"]) &&
					Str::contains($headers["Content-Disposition"][0], "attachment"),
			];
		} catch (RequestException $e) {
			// If HEAD fails, try GET with range
			return $this->analyzeWithGet($url);
		}
	}

	protected function analyzeWithGet(string $url): array
	{
		try {
			$response = $this->client->get($url, [
				"headers" => [
					"Range" => "bytes=0-1023", // Get first 1KB
				],
			]);

			$headers = $response->getHeaders();

			return [
				"headers" => $headers,
				"status" => $response->getStatusCode(),
				"content_type" =>
					$headers["Content-Type"][0] ?? "application/octet-stream",
				"content_length" => null, // Unknown for partial request
				"accept_ranges" => false,
				"content_disposition" => $headers["Content-Disposition"][0] ?? null,
				"is_attachment" =>
					isset($headers["Content-Disposition"]) &&
					Str::contains($headers["Content-Disposition"][0], "attachment"),
				"partial_content" => true,
			];
		} catch (\Exception $e) {
			return [
				"error" => $e->getMessage(),
				"status" => 0,
				"content_type" => "unknown",
			];
		}
	}

	public function getStrategy(
		string $url,
		array $info = []
	): DownloadStrategyInterface {
		foreach ($this->strategies as $strategy) {
			if ($strategy->supports($url)) {
				return $strategy;
			}
		}

		// Based on analysis, choose appropriate strategy
		$contentType = $info["content_type"] ?? "";
		$isAttachment = $info["is_attachment"] ?? false;
		$acceptRanges = $info["accept_ranges"] ?? false;

		if ($isAttachment || $acceptRanges) {
			return new \Modules\Downloader\Services\Strategies\DirectFileStrategy();
		}

		// Default to generic strategy
		return new \Modules\Downloader\Services\Strategies\GenericStrategy();
	}
}
