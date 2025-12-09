<?php

namespace Modules\Downloader\Services\Handlers;

use Illuminate\Support\Facades\Http;
use Modules\Downloader\Services\BaseDownloadHandler;

class GoogleDriveHandler extends BaseDownloadHandler
{
	public function supports(string $url): bool
	{
		return str_contains($url, "drive.google.com") &&
			str_contains($url, "/file/d/");
	}

	public function getInfo(string $url): array
	{
		// Extract file ID from Google Drive URL
		preg_match("/\/file\/d\/([^\/]+)/", $url, $matches);
		$fileId = $matches[1] ?? null;

		if (!$fileId) {
			throw new \Exception("Invalid Google Drive URL");
		}

		// Get download link (requires Google Drive API key in production)
		$downloadUrl = "https://drive.google.com/uc?id={$fileId}&export=download";

		return parent::getInfo($downloadUrl);
	}

	public function handle(
		string $url,
		string $savePath,
		array $options = []
	): array {
		// Extract file ID and get direct download link
		preg_match("/\/file\/d\/([^\/]+)/", $url, $matches);
		$fileId = $matches[1] ?? null;

		$downloadUrl = "https://drive.google.com/uc?id={$fileId}&export=download";

		// Use parent's direct download handling
		$directHandler = new DirectDownloadHandler();
		return $directHandler->handle($downloadUrl, $savePath, $options);
	}
}
