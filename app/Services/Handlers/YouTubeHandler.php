<?php

namespace Modules\Downloader\Services\Handlers;

use Modules\Downloader\Services\BaseDownloadHandler;

class YoutubeHandler extends BaseDownloadHandler
{
	public function supports(string $url): bool
	{
		$patterns = ["youtube.com/watch", "youtu.be/", "youtube.com/shorts/"];

		foreach ($patterns as $pattern) {
			if (str_contains($url, $pattern)) {
				return true;
			}
		}

		return false;
	}

	public function handle(
		string $url,
		string $savePath,
		array $options = []
	): array {
		// Note: YouTube downloading requires yt-dlp or similar tool
		// This is a simplified example
		throw new \Exception(
			"YouTube downloading requires external tools. Please install yt-dlp on server."
		);

		// In production, you would use:
		// $command = "yt-dlp -f 'best[ext=mp4]' -o '{$savePath}' '{$url}'";
		// exec($command, $output, $returnCode);
	}
}
