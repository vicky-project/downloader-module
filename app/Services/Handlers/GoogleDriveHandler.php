<?php
namespace Modules\Downloader\Services\Handlers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Downloader\Enums\UrlType;
use Modules\Downloader\Models\DownloadJob;

class GoogleDriveHandler extends BaseDownloadHandler
{
	protected string $name = "google_drive";
	protected int $priority = 90;

	public function supports(UrlType $urlType): bool
	{
		return $urlType === UrlType::GOOGLE_DRIVE;
	}

	public function getFilename(string $url): string
	{
		$fileId = $this->extractFileId($url);
		return $fileId
			? "google_drive_{$fileId}_" . time()
			: "google_drive_file_" . time();
	}

	public function validate(string $url): array
	{
		$fileId = $this->extractFileId($url);

		if (!$fileId) {
			return [
				"valid" => false,
				"message" => "Invalid Google Drive URL format",
			];
		}

		return [
			"valid" => true,
			"message" => null,
		];
	}

	public function getDirectDownloadUrl(string $url): ?string
	{
		$fileId = $this->extractFileId($url);
		if (!$fileId) {
			return null;
		}

		return "https://drive.google.com/uc?export=download&id=" . $fileId;
	}

	public function download(DownloadJob $downloadJob): void
	{
		$fileId = $this->extractFileId($downloadJob->url);
		if (!$fileId) {
			throw new \Exception("Invalid Google Drive URL");
		}

		// Try to get file info first
		$fileInfo = $this->getFileInfoFromApi($fileId);
		if ($fileInfo) {
			$downloadJob->update([
				"original_filename" =>
					$fileInfo["name"] ?? $downloadJob->original_filename,
			]);
		}

		// Use parent download with direct URL
		parent::download($downloadJob);
	}

	private function extractFileId(string $url): ?string
	{
		$patterns = ["/\/d\/([^\/]+)/", "/id=([^&]+)/", "/\/folders\/([^\/?]+)/"];

		foreach ($patterns as $pattern) {
			if (preg_match($pattern, $url, $matches)) {
				return $matches[1];
			}
		}

		return null;
	}

	private function getFileInfoFromApi(string $fileId): ?array
	{
		try {
			$apiKey = config("services.google_drive.api_key");
			if (!$apiKey) {
				return null;
			}

			$response = Http::withOptions([
				"headers" => $this->getDefaultHeaders(),
			])->get("https://www.googleapis.com/drive/v3/files/{$fileId}", [
				"key" => $apiKey,
				"fields" => "name,size,mimeType",
			]);

			if ($response->successful()) {
				return $response->json();
			}
		} catch (\Exception $e) {
			Log::warning("Google Drive API error: " . $e->getMessage());
		}

		return null;
	}
}
