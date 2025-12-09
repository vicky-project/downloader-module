<?php

namespace Modules\Downloader\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Number;
use Illuminate\Http\StreamedEvent;
use Illuminate\Support\Facades\Validator;
use Modules\Downloader\Models\Download;
use Modules\Downloader\Constants\Permissions;
use Modules\Downloader\Services\DownloadManager;
use Modules\Downloader\Enums\DownloadStatus;
use Modules\Downloader\Jobs\ProcessDownloadJob;

class DownloaderController extends Controller
{
	protected $downloadManager;

	public function __construct()
	{
		$this->downloadManager = new DownloadManager();

		$this->middleware(["permission:" . Permissions::VIEW_DOWNLOADERS])->only([
			"index",
		]);
	}

	/**
	 * Display a listing of the resource.
	 */
	public function index(Request $request)
	{
		$downloads = Download::where("user_id", \Auth::id())
			->orderBy("created_at", "desc")
			->paginate(20);

		return view("downloader::index", compact("downloads"));
	}

	/**
	 * Show the preview for downloading a file.
	 */
	public function preview(Request $request)
	{
		$validator = Validator::make($request->all(), [
			"url" => "required|url|max:2000",
		]);

		if ($validator->fails()) {
			return response()->json(
				[
					"success" => false,
					"message" => $validator->errors()->first(),
				],
				422
			);
		}

		try {
			$handler = $this->downloadManager->getHandler($request->url);
			$info = $handler->getInfo($request->url);

			$fileType = $this->determineFileType($info["mime_type"] ?? "");
			$extension = $this->getExtension(
				$info["filename"] ?? "",
				$info["'mime_type"] ?? ""
			);

			return response()->json([
				"success" => true,
				"data" => [
					"url" => $request->url,
					"filename" =>
						$info["filename"] ?? $this->extractFilenameFromUrl($request->url),
					"size" => $info["size"] ? Number::fileSize($info["size"]) : "Unknown",
					"size_bytes" => $info["size"] ?? 0,
					"mime_type" => $info["mime_type"] ?? "Unknown",
					"file_type" => $fileType,
					"extension" => $extension,
					"supports_resume" => $info["accept_ranges"] ?? false,
					"source_type" => $this->downloadManager->getType($request->url),
					"estimated_time" => $info["size"]
						? $this->estimateDownloadTime($info["size"])
						: "Unknown",
				],
			]);
		} catch (\Exception $e) {
			logger()->error("Unable to fetch file information: " . $e->getMessage(), [
				"error" => $e->getMessage(),
				"trace" => $e->getTraceAsString(),
			]);
			return response()->json(
				[
					"success" => false,
					"message" => "Unable to fetch file information: " . $e->getMessage(),
				],
				400
			);
		}
	}

	/**
	 * Start download process.
	 */
	public function startDownload(Request $request)
	{
		$request->validate(["url" => "required|url"]);

		\DB::beginTransaction();

		try {
			$downloadJob = $this->downloadManager->createDownload(
				\Auth::id(),
				$request->url
			);
			$downloadJob->update([
				"job_id" => str()
					->uuid()
					->toString(),
				"status" => DownloadStatus::PENDING,
			]);

			ProcessDownloadJob::dispatch($downloadJob);

			\DB::commit();

			return $request->wantsJson()
				? response()->json([
					"success" => true,
					"job_id" => $downloadJob->job_id,
					"message" => "Download started in background",
				])
				: back()->with("success", "Download started in background.");
		} catch (\Exception $e) {
			\DB::rollBack();
			logger()->error("Download failed: " . $e->getMessage(), [
				"error" => $e->getMessage(),
				"trace" => $e->getTrace(),
			]);
			return $request->wantsJson()
				? response()->json(
					[
						"success" => false,
						"message" => "Failed to start download: " . $e->getMessage(),
					],
					500
				)
				: back()->withErrors("Failed to start download: " . $e->getMessage());
		}
	}

	/**
	 * Event stream for active download.
	 */
	public function stream(Request $request, $job_id)
	{
		return response()->eventStream(
			function () use ($job_id) {
				$lastProgress = null;
				$lastUpdate = null;

				while (true) {
					if (connection_aborted()) {
						break;
					}

					$download = Download::where("job_id", $job_id)->first();

					if (!$download) {
						yield $this->sentEvent("error", ["error" => "Job not found."]);
						break;
					}

					if (
						$lastProgress !== $download->progress ||
						now()->diffInSeconds($lastUpdate) >= 2
					) {
						$data = [
							"progress" => $download->progress,
							"downloaded" => $download->downloaded_size,
							"total" => $download->total_size,
							"speed" => $download->speed,
							"time_remaining" => $download->time_remaining,
							"status" => $download->status,
							"filename" => $download->filename,
						];

						yield $this->sentEvent("progress", $data);

						$lastProgress = $download->progress;
						$lastUpdate = now();
					}

					if (
						in_array($download->status, [
							DownloadStatus::COMPLETED,
							DownloadStatus::FAILED,
							DownloadStatus::CANCELLED,
						])
					) {
						yield $this->sentEvent("completed", [
							"status" => $download->status,
						]);

						break;
					}

					sleep(1);
				}
			},
			[
				"Content-Type" => "text/event-stream",
				"Cache-Control" => "no-cache",
				"Connection" => "keep-alive",
				"X-Accel-Buffering" => "no",
			]
		);
	}

	/**
	 * Show the form for editing the specified resource.
	 */
	public function file($job_id)
	{
		$file = DownloadJob::where("job_id", $job_id)->first();
		$storage = Storage::disk("local");

		return $storage->response($storage->path($file->local_path));
	}

	/**
	 * Update the specified resource in storage.
	 */
	public function getDownloads()
	{
		$downloads = method_exists($user, "activeDownloads")
			? $user->activeDownloads()->get()
			: collect();

		return response()->json([
			"success" => true,
			"data" => $activeDownloads->map(function ($download) {
				return [
					"job_id" => $download->job_id,
					"status" => $download->status,
					"progress" => $download->progress,
					"filename" => $download->original_filename,
					"file_size" => $download->file_size,
					"started_at" => $download->created_at->toISOString(),
					"updated_at" => $download->updated_at->toISOString(),
				];
			}),
		]);
	}

	/**
	 * Remove the specified resource from storage.
	 */
	public function destroy($id)
	{
	}

	// Helper methods
	private function determineFileType($mimeType)
	{
		$mimeMap = [
			"image/" => "Image",
			"video/" => "Video",
			"audio/" => "Audio",
			"application/pdf" => "PDF",
			"application/msword" => "Word",
			"application/vnd.openxmlformats-officedocument.wordprocessingml.document" =>
				"Word",
			"application/vnd.ms-excel" => "Excel",
			"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" =>
				"Excel",
			"application/zip" => "Archive",
			"application/x-rar-compressed" => "Archive",
			"application/x-7z-compressed" => "Archive",
			"text/" => "Text",
		];

		foreach ($mimeMap as $key => $type) {
			if (strpos($mimeType, $key) === 0) {
				return $type;
			}
		}

		return "Unknown";
	}

	private function getExtension($filename, $mimeType)
	{
		// Try to get extension from filename
		$ext = pathinfo($filename, PATHINFO_EXTENSION);
		if ($ext) {
			return strtolower($ext);
		}

		// Try to determine from mime type
		$mimeToExt = [
			"image/jpeg" => "jpg",
			"image/png" => "png",
			"image/gif" => "gif",
			"image/webp" => "webp",
			"video/mp4" => "mp4",
			"video/mpeg" => "mpeg",
			"video/quicktime" => "mov",
			"audio/mpeg" => "mp3",
			"audio/wav" => "wav",
			"application/pdf" => "pdf",
			"application/zip" => "zip",
			"application/x-rar-compressed" => "rar",
			"text/plain" => "txt",
			"text/html" => "html",
			"application/json" => "json",
		];

		return $mimeToExt[$mimeType] ?? "unknown";
	}

	private function extractFilenameFromUrl($url)
	{
		$path = parse_url($url, PHP_URL_PATH);
		$filename = basename($path);
		return $filename ?: "download_" . time();
	}

	private function formatBytes($bytes, $precision = 2)
	{
		$units = ["B", "KB", "MB", "GB", "TB"];
		$bytes = max($bytes, 0);
		$pow = floor(($bytes ? log($bytes) : 0) / log(1024));
		$pow = min($pow, count($units) - 1);
		$bytes /= pow(1024, $pow);

		return round($bytes, $precision) . " " . $units[$pow];
	}

	private function estimateDownloadTime($sizeBytes)
	{
		// Assume average download speed of 5MB/s
		$averageSpeed = 5 * 1024 * 1024; // 5 MB in bytes
		$seconds = $sizeBytes / $averageSpeed;

		if ($seconds < 60) {
			return round($seconds) . " seconds";
		} elseif ($seconds < 3600) {
			return round($seconds / 60) . " minutes";
		} else {
			return round($seconds / 3600, 1) . " hours";
		}
	}

	private function sentEvent(string $event, array $data)
	{
		return new StreamedEvent(event: $event, data: $data);
	}
}
