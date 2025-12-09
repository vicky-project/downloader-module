<?php

namespace Modules\Downloader\Services;

use Modules\Downloader\Models\Download;
use Modules\Downloader\Services\Handlers\DirectDownloadHandler;
use Modules\Downloader\Services\Handlers\GoogleDriveHandler;
use Modules\Downloader\Services\Handlers\YoutubeHandler;

class DownloadManager
{
	protected $handlers = [];

	public function __construct()
	{
		$this->registerHandlers();
	}

	protected function registerHandlers()
	{
		$this->handlers = [
			new GoogleDriveHandler(),
			new YoutubeHandler(),
			new DirectDownloadHandler(), // Always last as fallback
		];
	}

	public function getHandler(string $url)
	{
		foreach ($this->handlers as $handler) {
			if ($handler->supports($url)) {
				return $handler;
			}
		}

		throw new \Exception("No handler found for URL: {$url}");
	}

	public function getType(string $url): string
	{
		$handler = $this->getHandler($url);

		return match (get_class($handler)) {
			GoogleDriveHandler::class => "google_drive",
			YoutubeHandler::class => "youtube",
			default => "direct",
		};
	}

	public function createDownload(
		int $userId,
		string $url,
		?string $filename = null
	): Download {
		$handler = $this->getHandler($url);
		$info = $handler->getInfo($url);

		$filename = $filename ?? ($info["filename"] ?? "download_" . time());

		return Download::create([
			"user_id" => $userId,
			"url" => $url,
			"type" => $this->getType($url),
			"filename" => $filename,
			"original_filename" => $info["filename"] ?? $filename,
			"total_size" => $info["size"],
			"mime_type" => $info["mime_type"],
			"metadata" => [
				"accept_ranges" => $info["accept_ranges"],
				"handler" => get_class($handler),
			],
		]);
	}
}
