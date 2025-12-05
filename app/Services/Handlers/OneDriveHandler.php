<?php
namespace Modules\Downloader\Services\Handlers;

use Modules\Downloader\Enums\UrlType;

class OneDriveHandler extends BaseDownloadHandler
{
	protected string $name = "one_drive";
	protected int $priority = 60;

	public function supports(UrlType $urlType): bool
	{
		return $urlType === UrlType::ONE_DRIVE;
	}

	public function getFilename(string $url): string
	{
		// Extract from URL or use generic name
		$resourceId = $this->extractResourceId($url);
		return $resourceId
			? "onedrive_{$resourceId}_" . time()
			: "onedrive_file_" . time();
	}

	public function validate(string $url): array
	{
		$resourceId = $this->extractResourceId($url);

		if (!$resourceId) {
			return [
				"valid" => false,
				"message" => "Invalid OneDrive URL format",
			];
		}

		return [
			"valid" => true,
			"message" => null,
		];
	}

	public function getDirectDownloadUrl(string $url): ?string
	{
		// Add download parameter to OneDrive URL
		if (
			str_contains($url, "onedrive.live.com") ||
			str_contains($url, "1drv.ms")
		) {
			return $url . (str_contains($url, "?") ? "&" : "?") . "download=1";
		}

		return $url;
	}

	private function extractResourceId(string $url): ?string
	{
		if (preg_match("/id=([^&]+)/", $url, $matches)) {
			return $matches[1];
		} elseif (preg_match("/\/redir\?(.*)/", $url, $matches)) {
			parse_str($matches[1], $params);
			return $params["resid"] ?? null;
		}

		return null;
	}
}
