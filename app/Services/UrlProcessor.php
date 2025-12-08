<?php

namespace Modules\Downloader\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Str;

class UrlProcessor
{
	protected $client;

	public function __construct()
	{
		$this->client = new Client([
			"timeout" => 30,
			"connect_timeout" => 10,
		]);
	}

	public function analyzeUrl(string $url): array
	{
		try {
			// Cek HEAD request untuk mendapatkan informasi file
			$response = $this->client->head($url, [
				"allow_redirects" => true,
				"headers" => [
					"User-Agent" =>
						"Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
				],
			]);

			$headers = $response->getHeaders();

			// Analisis URL untuk mendapatkan nama file
			$filename = $this->extractFilename($url, $headers);

			// Deteksi tipe konten
			$contentType = $headers["Content-Type"][0] ?? "application/octet-stream";
			$mimeType = explode(";", $contentType)[0];

			// Deteksi ekstensi
			$extension = $this->detectExtension($filename, $mimeType);

			// Ukuran file
			$fileSize = $headers["Content-Length"][0] ?? null;
			if ($fileSize) {
				$fileSize = (int) $fileSize;
			}

			// Cek support range (untuk resume)
			$acceptsRanges =
				isset($headers["Accept-Ranges"]) &&
				$headers["Accept-Ranges"][0] === "bytes";

			return [
				"success" => true,
				"url" => $url,
				"filename" => $filename,
				"file_size" => $fileSize,
				"mime_type" => $mimeType,
				"extension" => $extension,
				"accepts_ranges" => $acceptsRanges,
				"headers" => $headers,
			];
		} catch (RequestException $e) {
			// Jika HEAD gagal, coba dengan GET partial
			return $this->tryPartialGet($url);
		} catch (\Exception $e) {
			return [
				"success" => false,
				"error" => "Failed to analyze URL: " . $e->getMessage(),
				"url" => $url,
			];
		}
	}

	private function extractFilename(string $url, array $headers): string
	{
		// Cek Content-Disposition header
		if (isset($headers["Content-Disposition"][0])) {
			$contentDisposition = $headers["Content-Disposition"][0];
			if (preg_match('/filename="([^"]+)"/', $contentDisposition, $matches)) {
				return $matches[1];
			} elseif (
				preg_match("/filename='([^']+)'/", $contentDisposition, $matches)
			) {
				return $matches[1];
			} elseif (
				preg_match("/filename=([^\s;]+)/", $contentDisposition, $matches)
			) {
				return $matches[1];
			}
		}

		// Ekstrak dari URL
		$path = parse_url($url, PHP_URL_PATH);
		if ($path) {
			$basename = basename($path);
			if (!empty($basename)) {
				return $basename;
			}
		}

		// Generate nama file default
		return "download_" . time();
	}

	private function detectExtension(string $filename, string $mimeType): ?string
	{
		// Cek dari nama file
		$extension = pathinfo($filename, PATHINFO_EXTENSION);
		if (!empty($extension)) {
			return strtolower($extension);
		}

		// Map mime type ke extension
		$mimeMap = [
			"application/pdf" => "pdf",
			"image/jpeg" => "jpg",
			"image/png" => "png",
			"image/gif" => "gif",
			"application/zip" => "zip",
			"application/x-rar-compressed" => "rar",
			"application/x-7z-compressed" => "7z",
			"video/mp4" => "mp4",
			"video/x-matroska" => "mkv",
			"audio/mpeg" => "mp3",
			"text/plain" => "txt",
			"text/html" => "html",
			"application/json" => "json",
		];

		return $mimeMap[$mimeType] ?? null;
	}

	private function tryPartialGet(string $url): array
	{
		try {
			$response = $this->client->get($url, [
				"headers" => [
					"Range" => "bytes=0-1023", // Request kecil untuk analisis
					"User-Agent" =>
						"Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
				],
			]);

			$headers = $response->getHeaders();

			// Generate nama file default
			$filename = "download_" . time();

			return [
				"success" => true,
				"url" => $url,
				"filename" => $filename,
				"file_size" => null, // Tidak diketahui
				"mime_type" =>
					$headers["Content-Type"][0] ?? "application/octet-stream",
				"extension" => null,
				"accepts_ranges" => false,
				"headers" => $headers,
			];
		} catch (\Exception $e) {
			return [
				"success" => false,
				"error" => "URL tidak dapat diakses: " . $e->getMessage(),
				"url" => $url,
			];
		}
	}
}
