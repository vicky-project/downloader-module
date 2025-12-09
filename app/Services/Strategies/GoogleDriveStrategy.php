<?php

namespace Modules\Downloader\Services\Strategies;

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Promise;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Str;
use Modules\Downloader\Contracts\DownloadStrategyInterface;

class GoogleDriveStrategy implements DownloadStrategyInterface
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
			// Extract file ID from URL
			$fileId = $this->extractFileId($url);

			if (!$fileId) {
				throw new \Exception("Invalid Google Drive URL");
			}

			// Construct download URL
			$downloadUrl = $this->getDownloadUrl($fileId);

			$response = $this->client->head($downloadUrl, [
				"allow_redirects" => true,
			]);
			$headers = $response->getHeaders();

			$acceptsRanges =
				isset($headers["Accepts-Ranges"]) &&
				$headers["Accepts-Ranges"][0] === "bytes";

			$fileinfo = $this->extractFileInfoFromHeaders($headers, $url);

			return [
				"success" => true,
				"type" => "google_drive",
				"file_id" => $fileId,
				"filename" => $fileinfo["filename"] ?? "google_drive_file",
				"size" => $fileinfo["size"] ?? null,
				"mime_type" =>
					$headers["Content-Type"][0] ?? "application/octet-stream",
				"download_url" => $downloadUrl,
				"requires_confirmation" => $this->requiresConfirmation($fileId),
				"supports_chunking" => $acceptsRanges,
				"supports_resume" => $acceptsRanges,
				"accepts-ranges" => $acceptsRanges,
			];
		} catch (\Exception $e) {
			return [
				"success" => false,
				"error" => $e->getMessage(),
				"type" => "google_drive",
			];
		}
	}

	public function download(string $url, array $options = []): array
	{
		$info = $this->analyze($url);

		if (!$info["success"]) {
			throw new \Exception(
				"Failed to analyze Google Drive URL: " . $info["error"]
			);
		}

		$jobId = $options["job_id"] ?? uniqid("gdrive_", true);
		$connections = $options["connections"] ?? 4;
		$chunkSize = $options["chunk_size"] ?? 10 * 1024 * 1024;

		$confirmedDownloadUrl = $this->getConfirmedDownloadUrl(
			$info["download_url"],
			$info["file_id"]
		);

		if ($info["supports_chunking"] && $info["size"] > $chunkSize) {
			return $this->downloadWithChunksAndConnections(
				$confirmedDownloadUrl,
				$info["filename"],
				$info["size"],
				$jobId,
				$connections,
				$chunkSize
			);
		}

		// Handle confirmation for large files
		if ($info["requires_confirmation"]) {
			return $this->downloadWithConfirmation($info["download_url"], $info);
		}

		// Direct download
		return $this->directDownload($info["download_url"], $info["filename"]);
	}

	/**
	 * Download a file in chunks using multiple concurrent connections
	 */
	protected function downloadWithChunksAndConnections(
		string $downloadUrl,
		string $filename,
		int $fileSize,
		string $jobId,
		int $connections = 4,
		int $chunkSize = 10485760
	): array {
		// Calculate chunk boundaries
		$chunks = [];
		$tempDir = sys_get_temp_dir() . "/gdrive_downloads/" . $jobId;

		if (!file_exists($tempDir)) {
			mkdir($tempDir, 0755, true);
		}

		$finalPath = $tempDir . "/" . $filename;

		// Create promises for each chunk
		$promises = [];
		$chunkFiles = [];

		for ($i = 0; $i < $connections; $i++) {
			$startByte = floor(($fileSize / $connections) * $i);
			$endByte =
				$i == $connections - 1
					? $fileSize - 1
					: floor(($fileSize / $connections) * ($i + 1)) - 1;

			$chunkFilename = $tempDir . "/chunk_" . $i . ".part";
			$chunkFiles[] = $chunkFilename;

			// Create async request for each chunk
			$promises[] = $this->client->getAsync($downloadUrl, [
				"headers" => [
					"Range" => "bytes=" . $startByte . "-" . $endByte,
				],
				"sink" => $chunkFilename,
			]);
		}

		// Execute all promises concurrently
		$responses = Promise\Utils::settle($promises)->wait();

		// Check for failures
		foreach ($responses as $index => $response) {
			if ($response["state"] !== "fulfilled") {
				throw new \Exception(
					"Failed to download chunk $index: " .
						$response["reason"]->getMessage()
				);
			}
		}

		// Merge all chunks into final file
		$finalHandle = fopen($finalPath, "wb");
		foreach ($chunkFiles as $chunkFile) {
			$chunkHandle = fopen($chunkFile, "rb");
			stream_copy_to_stream($chunkHandle, $finalHandle);
			fclose($chunkHandle);
			unlink($chunkFile); // Clean up chunk file
		}
		fclose($finalHandle);

		// Clean up temp directory
		rmdir($tempDir);

		return [
			"success" => true,
			"temp_file" => $finalPath,
			"filename" => $filename,
			"downloaded_path" => $finalPath,
		];
	}

	/**
	 * Handle Google's virus scan confirmation page for large files
	 */
	protected function getConfirmedDownloadUrl(
		string $initialUrl,
		string $fileId
	): string {
		$cookieJar = new CookieJar();

		// First request might show confirmation page
		$response = $this->client->get($initialUrl, [
			"cookies" => $cookieJar,
			"allow_redirects" => false,
		]);

		$html = (string) $response->getBody();

		// Check for confirmation token (for files > 100MB)
		if (
			preg_match('/name="confirm" value="([^"]+)"/', $html, $matches) ||
			preg_match("/confirm=([^&]+)&/", $html, $matches)
		) {
			$confirmToken = $matches[1];

			// Make request with confirmation token
			$response = $this->client->get(
				$initialUrl . "&confirm=" . $confirmToken,
				[
					"cookies" => $cookieJar,
					"allow_redirects" => true,
				]
			);

			// The final URL after all redirects
			return $response->getHeaderLine("X-Guzzle-Redirect-History") ?:
				$response->getHeaderLine("Location") ?:
				$initialUrl;
		}

		return $initialUrl;
	}

	protected function downloadWithConfirmation(
		string $downloadUrl,
		array $info
	): array {
		$cookieJar = new CookieJar();

		// First request to get confirmation page
		$response = $this->client->get($downloadUrl, [
			"cookies" => $cookieJar,
			"allow_redirects" => false,
		]);

		$html = (string) $response->getBody();

		// Extract confirmation token
		if (preg_match('/name="confirm" value="([^"]+)"/', $html, $matches)) {
			$confirmToken = $matches[1];

			// Post confirmation
			$response = $this->client->post($downloadUrl, [
				"cookies" => $cookieJar,
				"form_params" => [
					"confirm" => $confirmToken,
				],
				"allow_redirects" => true,
			]);

			// Save file
			$tempFile = tempnam(sys_get_temp_dir(), "gd_");
			file_put_contents($tempFile, $response->getBody());

			return [
				"success" => true,
				"temp_file" => $tempFile,
				"filename" => $info["filename"],
			];
		}

		throw new \Exception("Failed to get confirmation token from Google Drive");
	}

	protected function directDownload(
		string $downloadUrl,
		string $filename
	): array {
		$tempFile = tempnam(sys_get_temp_dir(), "gd_");

		$this->client->get($downloadUrl, [
			"sink" => $tempFile,
			"headers" => [
				"Accept" => "*/*",
				"Accept-Encoding" => "gzip, deflate, br",
			],
		]);

		return [
			"success" => true,
			"temp_file" => $tempFile,
			"filename" => $filename,
		];
	}

	protected function extractFileId(string $url): ?string
	{
		// Multiple patterns for Google Drive URLs
		$patterns = [
			"/\/d\/([^\/]+)/", // /d/FILE_ID/
			"/id=([^&]+)/", // ?id=FILE_ID
			"/folders\/([^\/?]+)/", // /folders/FILE_ID
			"/file\/d\/([^\/]+)/", // /file/d/FILE_ID/
			"/open\?id=([^&]+)/", // /open?id=FILE_ID
		];

		foreach ($patterns as $pattern) {
			if (preg_match($pattern, $url, $matches)) {
				return $matches[1];
			}
		}

		return null;
	}

	protected function extractFileInfoFromHeaders(
		array $headers,
		string $url
	): array {
		$filename = "google_drive_file_" . time();
		$size = null;

		if (isset($headers["Content-Disposition"][0])) {
			$disposition = $headers["Content-Disposition"][0];
			if (preg_match('/filename="([^"]+)"/', $disposition, $matches)) {
				$filename = $matches[1];
			} elseif (preg_match("/filename='([^']+)'/", $disposition, $matches)) {
				$filename = $matches[1];
			}
		}

		if (isset($headers["Content-Length"][0])) {
			$size = (int) $headers["Content-Length"][0];
		}

		return [
			"filename" => $filename,
			"size" => $size,
		];
	}

	protected function getDownloadUrl(string $fileId): string
	{
		return "https://drive.google.com/uc?export=download&id=" . $fileId;
	}

	protected function requiresConfirmation(string $fileId): bool
	{
		// Large files require confirmation
		// We'll assume files over 100MB need confirmation
		return true; // Conservative approach
	}

	public function supports(string $url): bool
	{
		return Str::contains($url, [
			"drive.google.com",
			"docs.google.com",
			"google.com/drive",
		]);
	}
}
