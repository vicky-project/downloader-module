<?php

namespace Modules\Downloader\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Number;
use Illuminate\Support\Facades\Storage;
use Modules\Downloader\Models\DownloadJob;
use Modules\Downloader\Constants\Permissions;
use Modules\Downloader\Services\UrlProcessor;
use Modules\Downloader\Services\DownloadService;
use Modules\Downloader\Enums\DownloadStatus;
use Modules\Downloader\Jobs\ProcessDownloadJob;

class DownloaderController extends Controller
{
	protected UrlProcessor $processor;
	protected DownloadService $downloader;

	public function __construct(
		UrlProcessor $processor,
		DownloadService $downloader
	) {
		$this->processor = new UrlProcessor();
		$this->downloader = new DownloadService();

		$this->middleware(["permission:" . Permissions::VIEW_DOWNLOADERS])->only([
			"index",
		]);
	}

	/**
	 * Display a listing of the resource.
	 */
	public function index(Request $request)
	{
		return view("downloader::index");
	}

	/**
	 * Show the preview for downloading a file.
	 */
	public function analyzeUrl(Request $request)
	{
		$request->validate(["url" => "required|url"]);

		$result = $this->processor->analyzeUrl($request->url);

		if ($result["success"]) {
			return response()->json([
				"success" => true,
				"data" => [
					"filename" => $result["filename"],
					"file_size" => $result["file_size"],
					"formatted_size" => Number::fileSize($result["file_size"]),
					"mime_type" => $result["mime_type"],
					"extension" => $result["extension"],
					"accepts_ranges" => $result["accepts_ranges"],
				],
			]);
		}

		return response()->json(
			[
				"success" => false,
				"message" => $result["error"],
			],
			400
		);
	}

	/**
	 * Start download process.
	 */
	public function startDownload(Request $request)
	{
		$request->validate(["url" => "required|url"]);

		\Db::beginTransaction();

		try {
			$downloadJob = $this->downloader->startDownload(
				$request->url,
				\Auth::id()
			);

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
		return $this->eventStreamService->streamActiveDownloads((int) $job_id);
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
}
