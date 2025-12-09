<?php

namespace Modules\Downloader\Services\Handlers;

use Modules\Downloader\Services\BaseDownloadHandler;
use Generator;

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
	): Generator {
		// Note: YouTube downloading requires yt-dlp or similar tool
		// This is a simplified example that throws an exception

		yield [
			"downloaded" => 0,
			"total" => 0,
			"progress" => 0,
			"error" =>
				"YouTube downloading requires external tools. Please install yt-dlp on server.",
		];

		// Uncomment and implement for production with yt-dlp
		/*
        $command = "yt-dlp -f 'best[ext=mp4]' -o '{$savePath}' '{$url}' 2>&1";
        exec($command, $output, $returnCode);
        
        if ($returnCode === 0) {
            $fileSize = filesize($savePath) ?: 0;
            yield [
                'downloaded' => $fileSize,
                'total' => $fileSize,
                'progress' => 100,
                'completed' => true,
            ];
        } else {
            throw new \Exception("YouTube download failed: " . implode("\n", $output));
        }
        */
	}
}
