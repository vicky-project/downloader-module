<?php
namespace Modules\Downloader\Services\Handlers;

use Modules\Downloader\Enums\UrlType;

class DirectFileHandler extends BaseDownloadHandler
{
	protected string $name = "direct_file";
	protected int $priority = 100; // High priority for direct files

	public function supports(UrlType $urlType): bool
	{
		return $urlType === UrlType::DIRECT_FILE;
	}

	public function getFilename(string $url): string
	{
		return $this->extractFilenameFromUrl($url);
	}

	public function getDirectDownloadUrl(string $url): ?string
	{
		return $url;
	}
}
