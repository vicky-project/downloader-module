<?php

namespace Modules\Downloader\Services\Handlers;

use Modules\Downloader\Services\BaseDownloadHandler;

class DirectDownloadHandler extends BaseDownloadHandler
{
	public function supports(string $url): bool
	{
		$parsed = parse_url($url);
		return isset($parsed["scheme"]) &&
			in_array($parsed["scheme"], ["http", "https"]);
	}

	public function handle(
		string $url,
		string $savePath,
		array $options = []
	): array {
		$info = $this->getInfo($url);
		$startByte = $options["resume_from"] ?? 0;

		$totalSize = $info["size"];
		$downloaded = 0;
		$lastProgress = 0;

		foreach (
			$this->downloadChunked($url, $savePath, $startByte)
			as $chunkSize
		) {
			$downloaded = $chunkSize;

			// Calculate progress if total size is known
			$progress = $totalSize ? ($downloaded / $totalSize) * 100 : 0;

			// Only update if progress changed significantly
			if (abs($progress - $lastProgress) >= 0.1) {
				$lastProgress = $progress;

				return [
					"downloaded" => $downloaded,
					"total" => $totalSize,
					"progress" => round($progress, 2),
				];
			}
		}

		return [
			"downloaded" => $downloaded,
			"total" => $totalSize,
			"progress" => 100,
			"completed" => true,
		];
	}
}
