<?php
namespace Modules\Downloader\Services\Handlers;

use Modules\Downloader\Enums\UrlType;
use Modules\Downloader\Models\DownloadJob;

class YouTubeHandler extends BaseDownloadHandler
{
	protected string $name = "youtube";
	protected int $priority = 80;

	public function supports(UrlType $urlType): bool
	{
		return $urlType === UrlType::YOUTUBE;
	}

	public function getFilename(string $url): string
	{
		$videoId = $this->extractVideoId($url);
		return $videoId
			? "youtube_{$videoId}_" . time() . ".mp4"
			: "youtube_video_" . time() . ".mp4";
	}

	public function validate(string $url): array
	{
		$videoId = $this->extractVideoId($url);

		if (!$videoId) {
			return [
				"valid" => false,
				"message" => "Invalid YouTube URL format",
			];
		}

		return [
			"valid" => true,
			"message" => null,
		];
	}

	public function getDirectDownloadUrl(string $url): ?string
	{
		// YouTube requires special handling - return null to use custom download
		return null;
	}

	public function download(
		DownloadJob $downloadJob,
		?callable $progressCallback = null
	): void {
		// YouTube requires external tools like youtube-dl or yt-dlp
		// This is a simplified implementation
		$videoId = $this->extractVideoId($downloadJob->url);
		if (!$videoId) {
			throw new \Exception("Invalid YouTube URL");
		}

		// Here you would integrate with youtube-dl or similar tool
		// For now, we'll simulate the download process
		$this->downloadWithYoutubeDl($downloadJob, $videoId);
	}

	private function extractVideoId(string $url): ?string
	{
		$patterns = [
			"/youtube\.com\/watch\?v=([^&]+)/",
			"/youtu\.be\/([^?]+)/",
			"/youtube\.com\/embed\/([^?]+)/",
			"/youtube\.com\/v\/([^?]+)/",
			"/youtube\.com\/shorts\/([^?]+)/",
		];

		foreach ($patterns as $pattern) {
			if (preg_match($pattern, $url, $matches)) {
				return $matches[1];
			}
		}

		return null;
	}

	private function downloadWithYoutubeDl(
		DownloadJob $downloadJob,
		string $videoId
	): void {
		// This is a placeholder for actual youtube-dl integration
		// You would typically use symfony/process to execute youtube-dl

		$downloadJob->update([
			"progress" => 10,
			"metadata" => array_merge($downloadJob->metadata ?? [], [
				"youtube_video_id" => $videoId,
				"download_method" => "youtube-dl",
			]),
		]);

		// Simulate download process
		// In production, you would:
		// 1. Execute youtube-dl command
		// 2. Track progress
		// 3. Move file to storage

		throw new \Exception("YouTube download requires youtube-dl integration");
	}
}
