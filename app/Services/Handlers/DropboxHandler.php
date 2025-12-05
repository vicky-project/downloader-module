<?php
namespace Modules\Downloader\Services\Handlers;

use Modules\Downloader\Enums\UrlType;

class DropboxHandler extends BaseDownloadHandler
{
	protected string $name = "dropbox";
	protected int $priority = 70;

	public function supports(UrlType $urlType): bool
	{
		return $urlType === UrlType::DROPBOX;
	}

	public function getFilename(string $url): string
	{
		$path = parse_url($url, PHP_URL_PATH);
		$filename = basename($path);
		$filename = explode("?", $filename)[0]; // Remove query params

		return $filename ?: "dropbox_file_" . time();
	}

	public function validate(string $url): array
	{
		if (!str_contains($url, "dropbox.com")) {
			return [
				"valid" => false,
				"message" => "Not a Dropbox URL",
			];
		}

		return [
			"valid" => true,
			"message" => null,
		];
	}

	public function getDirectDownloadUrl(string $url): string
	{
		// Convert shared link to direct download link
		if (str_contains($url, "dropbox.com")) {
			$url = str_replace("dropbox.com", "dl.dropboxusercontent.com", $url);
			$url = preg_replace('/\?dl=0$/', "", $url);
			$url .= "?dl=1";
		}

		return $url;
	}
}
