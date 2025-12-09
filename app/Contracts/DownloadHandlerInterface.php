<?php
namespace Modules\Downloader\Contracts;

interface DownloadHandlerInterface
{
	public function handle(
		string $url,
		string $savePath,
		array $options = []
	): array;
	public function supports(string $url): bool;
	public function getInfo(string $url): array;
}
