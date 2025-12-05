<?php
namespace Modules\Downloader\Services;

use Modules\Downloader\Enums\UrlType;

class UrlResolverService
{
	/**
	 * Resolve and analyze URL type
	 */
	public function resolve(string $url): array
	{
		$urlType = $this->detectUrlType($url);

		return [
			"url" => $url,
			"type" => $urlType,
			"type_label" => $urlType->label(),
			"is_supported" => $this->isSupported($urlType),
			"metadata" => $this->extractMetadata($url, $urlType),
			"direct_download_url" => $this->getDirectDownloadUrl($url, $urlType),
			"requires_special_handling" => $this->requiresSpecialHandling($urlType),
		];
	}

	/**
	 * Detect URL type based on patterns
	 */
	public function detectUrlType(string $url): UrlType
	{
		// Clean and normalize URL
		$url = $this->normalizeUrl($url);

		// Check for Google Drive patterns
		if ($this->isGoogleDriveUrl($url)) {
			return UrlType::GOOGLE_DRIVE;
		}

		// Check for YouTube patterns
		if ($this->isYouTubeUrl($url)) {
			return UrlType::YOUTUBE;
		}

		// Check for Dropbox patterns
		if ($this->isDropboxUrl($url)) {
			return UrlType::DROPBOX;
		}

		// Check for OneDrive patterns
		if ($this->isOneDriveUrl($url)) {
			return UrlType::ONE_DRIVE;
		}

		// Check if it's a direct file URL
		if ($this->isDirectFileUrl($url)) {
			return UrlType::DIRECT_FILE;
		}

		return UrlType::OTHER;
	}

	/**
	 * Normalize URL for consistent processing
	 */
	private function normalizeUrl(string $url): string
	{
		$url = trim($url);

		// Ensure URL has protocol
		if (!preg_match("/^https?:\/\//", $url)) {
			$url = "https://" . $url;
		}

		return $url;
	}

	/**
	 * Check if URL is a Google Drive URL
	 */
	private function isGoogleDriveUrl(string $url): bool
	{
		$patterns = [
			"drive\.google\.com\/file\/d\/",
			"drive\.google\.com\/open\?id=",
			"drive\.google\.com\/uc\?id=",
			"drive\.google\.com\/drive\/folders\/",
			"drive\.google\.com\/drive\/u\/\d+\/folders\/",
		];

		foreach ($patterns as $pattern) {
			if (preg_match("/$pattern/", $url)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if URL is a YouTube URL
	 */
	private function isYouTubeUrl(string $url): bool
	{
		$patterns = [
			"youtube\.com\/watch\?v=",
			"youtu\.be\/",
			"youtube\.com\/embed\/",
			"youtube\.com\/v\/",
			"youtube\.com\/shorts\/",
		];

		foreach ($patterns as $pattern) {
			if (preg_match("/$pattern/", $url)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if URL is a Dropbox URL
	 */
	private function isDropboxUrl(string $url): bool
	{
		return str_contains($url, "dropbox.com") ||
			str_contains($url, "dl.dropboxusercontent.com");
	}

	/**
	 * Check if URL is a OneDrive URL
	 */
	private function isOneDriveUrl(string $url): bool
	{
		$patterns = ["onedrive\.live\.com", "1drv\.ms", "sharepoint\.com"];

		foreach ($patterns as $pattern) {
			if (str_contains($url, $pattern)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if URL points to a direct file
	 */
	private function isDirectFileUrl(string $url): bool
	{
		// Common file extensions
		$extensions = [
			"pdf",
			"doc",
			"docx",
			"xls",
			"xlsx",
			"ppt",
			"pptx",
			"jpg",
			"jpeg",
			"png",
			"gif",
			"bmp",
			"svg",
			"webp",
			"mp4",
			"avi",
			"mov",
			"wmv",
			"flv",
			"mkv",
			"webm",
			"mp3",
			"wav",
			"flac",
			"ogg",
			"m4a",
			"zip",
			"rar",
			"7z",
			"tar",
			"gz",
			"txt",
			"csv",
			"json",
			"xml",
			"exe",
			"msi",
			"apk",
			"dmg",
		];

		$path = parse_url($url, PHP_URL_PATH);
		if (!$path) {
			return false;
		}

		$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		return in_array($extension, $extensions);
	}

	/**
	 * Extract metadata from URL based on type
	 */
	private function extractMetadata(string $url, UrlType $urlType): array
	{
		$metadata = [];

		switch ($urlType) {
			case UrlType::GOOGLE_DRIVE:
				$metadata = $this->extractGoogleDriveMetadata($url);
				break;
			case UrlType::YOUTUBE:
				$metadata = $this->extractYouTubeMetadata($url);
				break;
			case UrlType::DROPBOX:
				$metadata = $this->extractDropboxMetadata($url);
				break;
			case UrlType::ONE_DRIVE:
				$metadata = $this->extractOneDriveMetadata($url);
				break;
			case UrlType::DIRECT_FILE:
				$metadata = $this->extractDirectFileMetadata($url);
				break;
		}

		return $metadata;
	}

	/**
	 * Extract Google Drive metadata
	 */
	private function extractGoogleDriveMetadata(string $url): array
	{
		$metadata = [];

		// Extract file ID from various Google Drive URL formats
		if (preg_match("/\/d\/([^\/]+)/", $url, $matches)) {
			$metadata["file_id"] = $matches[1];
		} elseif (preg_match("/id=([^&]+)/", $url, $matches)) {
			$metadata["file_id"] = $matches[1];
		} elseif (preg_match("/\/folders\/([^\/?]+)/", $url, $matches)) {
			$metadata["folder_id"] = $matches[1];
		}

		// Check if it's a viewable link
		$metadata["is_view_link"] =
			str_contains($url, "/view") || str_contains($url, "/preview");

		// Check if it's a direct download link
		$metadata["is_download_link"] =
			str_contains($url, "/uc?export=download") ||
			str_contains($url, "/uc?id=") ||
			str_contains($url, "&export=download");

		return $metadata;
	}

	/**
	 * Extract YouTube metadata
	 */
	private function extractYouTubeMetadata(string $url): array
	{
		$metadata = [];

		// Extract video ID from various YouTube URL formats
		if (preg_match("/v=([^&]+)/", $url, $matches)) {
			$metadata["video_id"] = $matches[1];
		} elseif (preg_match("/youtu\.be\/([^?]+)/", $url, $matches)) {
			$metadata["video_id"] = $matches[1];
		} elseif (preg_match("/embed\/([^?]+)/", $url, $matches)) {
			$metadata["video_id"] = $matches[1];
		} elseif (preg_match("/v\/([^?]+)/", $url, $matches)) {
			$metadata["video_id"] = $matches[1];
		}

		// Extract timestamp if present
		if (preg_match("/t=([^&]+)/", $url, $matches)) {
			$metadata["timestamp"] = $matches[1];
		}

		// Check if it's a playlist
		$metadata["is_playlist"] = str_contains($url, "list=");

		if (
			$metadata["is_playlist"] &&
			preg_match("/list=([^&]+)/", $url, $matches)
		) {
			$metadata["playlist_id"] = $matches[1];
		}

		return $metadata;
	}

	/**
	 * Extract Dropbox metadata
	 */
	private function extractDropboxMetadata(string $url): array
	{
		$metadata = [];

		// Check if it's a direct download link
		$metadata["is_direct"] =
			str_contains($url, "dl=1") ||
			str_contains($url, "dl.dropboxusercontent.com");

		// Extract file path from Dropbox URL
		if (
			preg_match("/\/s\/([^?]+)/", $url, $matches) ||
			preg_match("/\/u\/(\d+)\/s\/([^?]+)/", $url, $matches)
		) {
			$metadata["file_path"] = $matches[1] ?? ($matches[2] ?? null);
		}

		return $metadata;
	}

	/**
	 * Extract OneDrive metadata
	 */
	private function extractOneDriveMetadata(string $url): array
	{
		$metadata = [];

		// Extract resource ID from OneDrive URL
		if (preg_match("/id=([^&]+)/", $url, $matches)) {
			$metadata["resource_id"] = $matches[1];
		} elseif (preg_match("/\/redir\?(.*)/", $url, $matches)) {
			parse_str($matches[1], $params);
			$metadata["resource_id"] = $params["resid"] ?? null;
		}

		// Check if it's a direct download link
		$metadata["is_direct"] = str_contains($url, "download=1");

		return $metadata;
	}

	/**
	 * Extract direct file metadata
	 */
	private function extractDirectFileMetadata(string $url): array
	{
		$path = parse_url($url, PHP_URL_PATH);

		return [
			"filename" => basename($path),
			"extension" => pathinfo($path, PATHINFO_EXTENSION),
			"basename" => pathinfo($path, PATHINFO_FILENAME),
		];
	}

	/**
	 * Get direct download URL if possible
	 */
	private function getDirectDownloadUrl(string $url, UrlType $urlType): ?string
	{
		switch ($urlType) {
			case UrlType::DROPBOX:
				return $this->convertDropboxToDirectUrl($url);
			case UrlType::DIRECT_FILE:
				return $url;
			default:
				return null;
		}
	}

	/**
	 * Convert Dropbox shared link to direct download URL
	 */
	private function convertDropboxToDirectUrl(string $url): string
	{
		// If already a direct URL, return as is
		if (str_contains($url, "dl.dropboxusercontent.com")) {
			return $url;
		}

		// Convert shared link to direct download link
		if (str_contains($url, "dropbox.com")) {
			$url = str_replace("dropbox.com", "dl.dropboxusercontent.com", $url);
			$url = preg_replace('/\?dl=0$/', "", $url);
			$url .= "?dl=1";
		}

		return $url;
	}

	/**
	 * Check if URL type is supported for direct download
	 */
	private function isSupported(UrlType $urlType): bool
	{
		$supportedTypes = [
			UrlType::DIRECT_FILE,
			UrlType::GOOGLE_DRIVE,
			UrlType::YOUTUBE,
			UrlType::DROPBOX,
		];

		return in_array($urlType, $supportedTypes);
	}

	/**
	 * Check if URL requires special handling
	 */
	private function requiresSpecialHandling(UrlType $urlType): bool
	{
		return in_array($urlType, [
			UrlType::GOOGLE_DRIVE,
			UrlType::YOUTUBE,
			UrlType::ONE_DRIVE,
		]);
	}

	/**
	 * Validate URL format
	 */
	public function validateUrl(string $url): array
	{
		$url = $this->normalizeUrl($url);

		// Basic URL validation
		if (!filter_var($url, FILTER_VALIDATE_URL)) {
			return [
				"valid" => false,
				"error" => "Invalid URL format",
			];
		}

		// Check URL scheme
		$scheme = parse_url($url, PHP_URL_SCHEME);
		if (!in_array($scheme, ["http", "https"])) {
			return [
				"valid" => false,
				"error" => "URL must use HTTP or HTTPS protocol",
			];
		}

		// Check for blocked domains (optional)
		$blockedDomains = config("downloader.blocked_domains", []);
		$host = parse_url($url, PHP_URL_HOST);

		foreach ($blockedDomains as $blocked) {
			if (str_contains($host, $blocked)) {
				return [
					"valid" => false,
					"error" => "URL domain is blocked",
				];
			}
		}

		return [
			"valid" => true,
			"normalized_url" => $url,
		];
	}
}
