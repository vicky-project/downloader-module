<?php

namespace Modules\Downloader\Contracts;

use Generator;

interface DownloadHandlerInterface
{
	/**
	 * Handle the download process
	 *
	 * @param string $url URL to download
	 * @param string $savePath Path to save the file
	 * @param array $options Additional options
	 * @return Generator Yields progress updates
	 */
	public function handle(
		string $url,
		string $savePath,
		array $options = []
	): Generator;

	/**
	 * Check if handler supports the URL
	 */
	public function supports(string $url): bool;

	/**
	 * Get file information from URL
	 */
	public function getInfo(string $url): array;
}
