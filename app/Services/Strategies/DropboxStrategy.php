<?php

namespace Modules\Downloader\Services\Strategies;

use GuzzleHttp\Client;
use Illuminate\Support\Str;
use Modules\Downloader\Contracts\DownloadStrategyInterface;

class DropboxStrategy implements DownloadStrategyInterface
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
			// Convert Dropbox sharing URL to direct download URL
			$directUrl = $this->convertToDirectUrl($url);

			$response = $this->client->head($directUrl);
			$headers = $response->getHeaders();

			$filename = $this->extractFilename($url, $headers);
			$size = $headers["Content-Length"][0] ?? null;

			return [
				"success" => true,
				"type" => "dropbox",
				"filename" => $filename,
				"size" => $size ? (int) $size : null,
				"direct_url" => $directUrl,
				"accepts_ranges" => true,
				"supports_chunking" => true,
				"supports_resume" => true,
			];
		} catch (\Exception $e) {
			return [
				"success" => false,
				"error" => $e->getMessage(),
				"type" => "dropbox",
			];
		}
	}

	public function download(string $url, array $options = []): array
	{
		$info = $this->analyze($url);

		if (!$info["success"]) {
			throw new \Exception("Failed to analyze Dropbox URL: " . $info["error"]);
		}

		// Use direct file strategy for download
		$directStrategy = new DirectFileStrategy();
		return $directStrategy->download($info["direct_url"], $options);
	}

	protected function convertToDirectUrl(string $url): string
	{
		// Convert Dropbox sharing URL to direct download
		// https://www.dropbox.com/s/ID/filename?dl=0 -> https://dl.dropboxusercontent.com/s/ID/filename

		$url = str_replace("www.dropbox.com", "dl.dropboxusercontent.com", $url);
		$url = str_replace("?dl=0", "", $url);
		$url = str_replace("?dl=1", "", $url);

		if (!Str::contains($url, "dl.dropboxusercontent.com")) {
			// Try to construct direct URL
			if (preg_match("/dropbox\.com\/s\/([^\/]+)\/([^?]+)/", $url, $matches)) {
				$id = $matches[1];
				$filename = $matches[2];
				return "https://dl.dropboxusercontent.com/s/{$id}/{$filename}";
			}
		}

		return $url;
	}

	protected function extractFilename(string $url, array $headers): string
	{
		if (isset($headers["Content-Disposition"][0])) {
			$disposition = $headers["Content-Disposition"][0];
			if (preg_match('/filename="([^"]+)"/', $disposition, $matches)) {
				return $matches[1];
			}
		}

		// Extract from URL
		$path = parse_url($url, PHP_URL_PATH);
		if ($path) {
			$parts = explode("/", $path);
			if (count($parts) >= 3) {
				return $parts[2]; // Dropbox pattern: /s/id/filename
			}
		}

		return "dropbox_file_" . time();
	}

	public function supports(string $url): bool
	{
		return Str::contains($url, ["dropbox.com", "dropboxusercontent.com"]);
	}
}
