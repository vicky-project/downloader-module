<?php
namespace Modules\Downloader\Services\Handlers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\Downloader\Contracts\DownloadHandlerInterface;
use Modules\Downloader\Models\DownloadJob;
use Modules\Downloader\Enums\UrlType;

abstract class BaseDownloadHandler implements DownloadHandlerInterface
{
	protected string $name = "base_handler";
	protected int $priority = 0;

	/**
	 * Get handler name
	 */
	public function getName(): string
	{
		return $this->name;
	}

	/**
	 * Get handler priority
	 */
	public function getPriority(): int
	{
		return $this->priority;
	}

	/**
	 * Default validation
	 */
	public function validate(string $url): array
	{
		return [
			"valid" => true,
			"message" => null,
		];
	}

	/**
	 * Default get file info
	 */
	public function getFileInfo(string $url): array
	{
		try {
			$client = new \GuzzleHttp\Client([
				"timeout" => 15,
				"connect_timeout" => 10,
				"allow_redirects" => true,
				"headers" => $this->getDefaultHeaders(),
			]);

			$response = $client->head($url);

			return [
				"size" => $response->getHeaderLine("Content-Length"),
				"type" => $response->getHeaderLine("Content-Type"),
				"content_type" => $response->getHeaderLine("Content-Type"),
				"last_modified" => $response->getHeaderLine("Last-Modified"),
				"accept_ranges" => $response->getHeaderLine("Accept-Ranges"),
				"etag" => $response->getHeaderLine("ETag"),
			];
		} catch (\Exception $e) {
			return [
				"error" => $e->getMessage(),
				"size" => null,
				"type" => null,
			];
		}
	}

	/**
	 * Default download implementation
	 */
	public function download(DownloadJob $downloadJob): void
	{
		$url = $this->getDirectDownloadUrl($downloadJob->url) ?? $downloadJob->url;

		$response = Http::timeout(300)
			->withOptions([
				"progress" => function ($downloadTotal, $downloadedBytes) use (
					$downloadJob
				) {
					if ($downloadTotal > 0) {
						$progress = ($downloadedBytes / $downloadTotal) * 100;
						$downloadJob->update(["progress" => $progress]);
					}
				},
				"headers" => $this->getDefaultHeaders(),
			])
			->get($url);

		if ($response->successful()) {
			$fileContent = $response->body();
			$localPath =
				"downloads/" . $downloadJob->user_id . "/" . $downloadJob->filename;

			Storage::disk("local")->put($localPath, $fileContent);

			$downloadJob->update([
				"local_path" => $localPath,
				"file_size" => Storage::disk("local")->size($localPath),
			]);
		} else {
			throw new \Exception("Failed to download file: " . $response->status());
		}
	}

	/**
	 * Get default headers for HTTP requests
	 */
	protected function getDefaultHeaders(): array
	{
		return [
			"User-Agent" =>
				"Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
			"Accept" => "*/*",
			"Accept-Encoding" => "gzip, deflate",
			"Accept-Language" => "en-US,en;q=0.9",
		];
	}

	/**
	 * Generate safe filename
	 */
	protected function generateSafeFilename(string $filename): string
	{
		$safeName = preg_replace("/[^a-zA-Z0-9\._-]/", "_", $filename);
		$name = pathinfo($safeName, PATHINFO_FILENAME);
		$extension = pathinfo($safeName, PATHINFO_EXTENSION);

		return $name . "_" . time() . "." . $extension;
	}

	/**
	 * Extract filename from URL
	 */
	protected function extractFilenameFromUrl(string $url): string
	{
		$path = parse_url($url, PHP_URL_PATH);
		$filename = basename($path);

		return $filename ?: "downloaded_file_" . time();
	}
}
