<?php

namespace Modules\Downloader\Services\Handlers;

use Modules\Downloader\Services\BaseDownloadHandler;
use Generator;

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
	): Generator {
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

			// Only yield if progress changed significantly
			if (abs($progress - $lastProgress) >= 0.1 || $downloaded == $totalSize) {
				$lastProgress = $progress;

				yield [
					"downloaded" => $downloaded,
					"total" => $totalSize,
					"progress" => round($progress, 2),
				];
			}
		}

		// Final yield when download is complete
		yield [
			"downloaded" => $downloaded,
			"total" => $totalSize,
			"progress" => 100,
			"completed" => true,
		];
	}
}
