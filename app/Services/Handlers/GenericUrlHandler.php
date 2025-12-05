<?php
namespace Modules\Downloader\Services\Handlers;

use Modules\Downloader\Enums\UrlType;

class GenericUrlHandler extends BaseDownloadHandler
{
	protected string $name = "generic_url";
	protected int $priority = 10; // Lowest priority - fallback handler

	public function supports(UrlType $urlType): bool
	{
		// This handler supports all URL types as fallback
		return true;
	}

	public function getFilename(string $url): string
	{
		$filename = $this->extractFilenameFromUrl($url);

		// Add URL type to filename for generic URLs
		$host = parse_url($url, PHP_URL_HOST);
		$host = $host ? str_replace(".", "_", $host) : "unknown";

		return "{$host}_" . $filename;
	}

	public function validate(string $url): array
	{
		// Basic URL validation
		if (!filter_var($url, FILTER_VALIDATE_URL)) {
			return [
				"valid" => false,
				"message" => "Invalid URL format",
			];
		}

		return [
			"valid" => true,
			"message" => null,
		];
	}

	public function getDirectDownloadUrl(string $url): ?string
	{
		return $url;
	}
}
