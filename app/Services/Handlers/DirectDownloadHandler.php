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
		$downloaded = $startByte;
		$lastYieldedProgress = 0;
		$lastYieldedTime = microtime(true);

		logger()->info("Download info", [
			"total_size" => $totalSize,
			"start_byte" => $startByte,
			"supports_resume" => $info["accept_ranges"],
		]);

		yield [
			"downloaded" => $downloaded,
			"total" => $totalSize,
			"progress" => $totalSize ? ($downloaded / $totalSize) * 100 : 0,
		];

		$chunkCount = 0;

		foreach (
			$this->downloadChunked($url, $savePath, $startByte)
			as $chunkSize
		) {
			$chunkCount++;
			$downloaded = $chunkSize;

			$currentTime = microtime(true);
			$timeSinceLastYield = $currentTime - $lastYieldedTime;

			// Calculate progress if total size is known
			$progress = $totalSize ? ($downloaded / $totalSize) * 100 : 0;

			// Only yield if progress changed significantly
			if (
				abs($progress - $lastYieldedProgress) >= 0.1 ||
				$timeSinceLastYield >= 0.5 ||
				$progress >= 99.9
			) {
				logger()->debug("Yield progress", [
					"chunk" => $chunkCount,
					"downloaded" => $downloaded,
					"total" => $totalSize,
					"progress" => $progress,
					"time_since_last_yield" => $timeSinceLastYield,
				]);
				yield [
					"downloaded" => $downloaded,
					"total" => $totalSize,
					"progress" => round($progress, 2),
				];

				$lastYieldedProgress = $progress;
				$lastYieldedTime = $currentTime;
			}

			if ($downloaded >= $totalSize && $totalSize > 0) {
				break;
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
