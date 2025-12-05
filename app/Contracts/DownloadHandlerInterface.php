<?php
namespace Modules\Downloader\Contracts;

use Modules\Downloader\Models\DownloadJob;
use Modules\Downloader\Enums\UrlType;

interface DownloadHandlerInterface
{
	/**
	 * Check if this handler supports the given URL
	 */
	public function supports(UrlType $urlType): bool;

	/**
	 * Get the filename for the URL
	 */
	public function getFilename(string $url): string;

	/**
	 * Validate if the URL can be downloaded
	 */
	public function validate(string $url): array;

	/**
	 * Get direct download URL (if available)
	 */
	public function getDirectDownloadUrl(string $url): ?string;

	/**
	 * Execute the download process
	 */
	public function download(DownloadJob $downloadJob): void;

	/**
	 * Get file information
	 */
	public function getFileInfo(string $url): array;

	/**
	 * Get handler name
	 */
	public function getName(): string;

	/**
	 * Get handler priority (higher = tried first)
	 */
	public function getPriority(): int;
}
