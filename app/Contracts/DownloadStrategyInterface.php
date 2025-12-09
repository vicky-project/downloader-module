<?php

namespace Modules\Downloader\Contracts;

interface DownloadStrategyInterface
{
	public function analyze(string $url): array;
	public function download(string $url, array $options = []): array;
	public function supports(string $url): bool;
}
