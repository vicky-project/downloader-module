<?php

namespace Modules\Downloader\Services\Strategies;

use GuzzleHttp\Client;
use Illuminate\Support\Str;
use Modules\Downloader\Contracts\DownloadStrategyInterface;

class OneDriveStrategy implements DownloadStrategyInterface
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
			// Convert OneDrive sharing URL to direct download URL
			$directUrl = $this->convertToDirectUrl($url);

			$response = $this->client->head($directUrl);
			$headers = $response->getHeaders();

			$filename = $this->extractFilename($url, $headers);
			$size = $headers["Content-Length"][0] ?? null;

			return [
				"success" => true,
				"type" => "onedrive",
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
				"type" => "onedrive",
			];
		}
	}

	public function download(string $url, array $options = []): array
	{
		$info = $this->analyze($url);

		if (!$info["success"]) {
			throw new \Exception("Failed to analyze OneDrive URL: " . $info["error"]);
		}

		// Use direct file strategy for download
		$directStrategy = new DirectFileStrategy();
		return $directStrategy->download($info["direct_url"], $options);
	}

	protected function convertToDirectUrl(string $url): string
	{
		// Convert OneDrive sharing URL to direct download
		// Example: https://1drv.ms/u/s!ABC123 -> https://api.onedrive.com/v1.0/shares/u!ABC123/root/content

		if (Str::contains($url, "1drv.ms")) {
			// Short URL - need to expand first
			$response = $this->client->get($url, ["allow_redirects" => false]);
			$location = $response->getHeaderLine("Location");

			if ($location) {
				$url = $location;
			}
		}

		// Convert sharing link to direct download
		if (preg_match("/\/redir\?(.+)/", $url, $matches)) {
			parse_str($matches[1], $params);
			if (isset($params["resid"]) && isset($params["authkey"])) {
				return "https://api.onedrive.com/v1.0/drive/items/{$params["resid"]}/content";
			}
		}

		// Try to extract resource ID
		if (preg_match("/resid=([^&]+)/", $url, $matches)) {
			$resid = $matches[1];
			return "https://api.onedrive.com/v1.0/drive/items/{$resid}/content";
		}

		return $url . "?download=1";
	}

	protected function extractFilename(string $url, array $headers): string
	{
		if (isset($headers["Content-Disposition"][0])) {
			$disposition = $headers["Content-Disposition"][0];
			if (preg_match('/filename="([^"]+)"/', $disposition, $matches)) {
				return $matches[1];
			}
		}

		return "onedrive_file_" . time();
	}

	public function supports(string $url): bool
	{
		return Str::contains($url, [
			"onedrive.live.com",
			"1drv.ms",
			"sharepoint.com",
		]);
	}
}
