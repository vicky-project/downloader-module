<?php

namespace Modules\Downloader\Services\Strategies;

use GuzzleHttp\Client;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Modules\Downloader\Contracts\DownloadStrategyInterface;

class StreamingStrategy implements DownloadStrategyInterface
{
	protected $client;

	public function __construct()
	{
		$this->client = new Client([
			"timeout" => 30,
			"connect_timeout" => 10,
			"headers" => [
				"User-Agent" =>
					"Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
			],
		]);
	}

	public function analyze(string $url): array
	{
		try {
			// For streaming sites, we need special handling
			$platform = $this->detectPlatform($url);

			return [
				"success" => true,
				"type" => "streaming",
				"platform" => $platform,
				"requires_tool" => true,
				"tool" => $this->getRecommendedTool($platform),
				"supports_chunking" => false,
				"supports_resume" => false,
			];
		} catch (\Exception $e) {
			return [
				"success" => false,
				"error" => $e->getMessage(),
				"type" => "streaming",
			];
		}
	}

	public function download(string $url, array $options = []): array
	{
		$info = $this->analyze($url);

		if (!$info["success"]) {
			throw new \Exception(
				"Failed to analyze streaming URL: " . $info["error"]
			);
		}

		// Use yt-dlp or youtube-dl for streaming sites
		return $this->downloadWithTool($url, $info["platform"]);
	}

	protected function downloadWithTool(string $url, string $platform): array
	{
		$tempDir = sys_get_temp_dir() . "/stream_downloads/" . uniqid();
		if (!file_exists($tempDir)) {
			mkdir($tempDir, 0755, true);
		}

		$tool = $this->getRecommendedTool($platform);
		$command = $this->buildCommand($tool, $url, $tempDir);

		$process = new Process($command);
		$process->setTimeout(3600); // 1 hour timeout
		$process->setIdleTimeout(300); // 5 minute idle timeout

		try {
			$process->mustRun();

			// Find downloaded file
			$files = glob($tempDir . "/*");
			if (empty($files)) {
				throw new \Exception("No file was downloaded");
			}

			$downloadedFile = $files[0];

			return [
				"success" => true,
				"temp_file" => $downloadedFile,
				"temp_dir" => $tempDir,
				"output" => $process->getOutput(),
			];
		} catch (ProcessFailedException $e) {
			throw new \Exception("Download failed: " . $e->getMessage());
		}
	}

	protected function detectPlatform(string $url): string
	{
		if (Str::contains($url, "youtube.com") || Str::contains($url, "youtu.be")) {
			return "youtube";
		}

		if (Str::contains($url, "vimeo.com")) {
			return "vimeo";
		}

		if (Str::contains($url, "dailymotion.com")) {
			return "dailymotion";
		}

		if (Str::contains($url, "twitch.tv")) {
			return "twitch";
		}

		return "generic_streaming";
	}

	protected function getRecommendedTool(string $platform): string
	{
		// Check which tools are available
		$tools = ["yt-dlp", "youtube-dl", "ffmpeg"];

		foreach ($tools as $tool) {
			$process = new Process(["which", $tool]);
			$process->run();

			if ($process->isSuccessful()) {
				return $tool;
			}
		}

		throw new \Exception(
			"No download tool is available. Please install yt-dlp or youtube-dl."
		);
	}

	protected function buildCommand(
		string $tool,
		string $url,
		string $outputDir
	): array {
		$baseCommand = [
			$tool,
			$url,
			"-o",
			$outputDir . "/%(title)s.%(ext)s",
			"--no-warnings",
			"--ignore-errors",
			"--no-playlist",
		];

		// Add quality options
		if ($tool === "yt-dlp") {
			array_push($baseCommand, "-f", "bestvideo+bestaudio/best");
		} else {
			array_push($baseCommand, "-f", "best");
		}

		return $baseCommand;
	}

	public function supports(string $url): bool
	{
		return Str::contains($url, [
			"youtube.com",
			"youtu.be",
			"vimeo.com",
			"dailymotion.com",
			"twitch.tv",
			"facebook.com/watch",
			"instagram.com/p/",
			"tiktok.com/@",
		]);
	}
}
