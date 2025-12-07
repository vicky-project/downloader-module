<?php

namespace Modules\Downloader\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Downloader\Models\DownloadJob;
use Modules\Downloader\Constants\Permissions;
use Modules\Downloader\Services\DownloadService;
use Modules\Downloader\Services\EventStreamService;

class DownloaderController extends Controller
{
	protected DownloadService $downloadService;
	protected EventStreamService $eventStreamService;

	public function __construct(
		DownloadService $downloadService,
		EventStreamService $eventStreamService
	) {
		$this->downloadService = $downloadService;
		$this->eventStreamService = $eventStreamService;

		$this->middleware(["permission:" . Permissions::VIEW_DOWNLOADERS])->only([
			"index",
		]);
	}

	/**
	 * Display a listing of the resource.
	 */
	public function index(Request $request)
	{
		$user = \Auth::user();

		$activeDownloads = method_exists($user, "activeDownloads")
			? $user->activeDownloads()->get()
			: collect();
		$completedDownloads = method_exists($user, "completedDownloads")
			? $user
				->completedDownloads()
				->latest()
				->take(10)
				->get()
			: collect();

		$userStats = $this->downloadService->getUserStats($user->id);
		dd($activeDownloads);

		return view(
			"downloader::index",
			compact("activeDownloads", "completedDownloads", "userStats")
		);
	}

	/**
	 * Show the preview for downloading a file.
	 */
	public function previewDownload(Request $request)
	{
		$request->validate(["url" => "required|url|max:2000"]);

		$preview = $this->downloadService->previewFile($request->url);

		return response()->json(["success" => true, "data" => $preview]);
	}

	/**
	 * Start download process.
	 */
	public function startDownload(Request $request)
	{
		$request->validate(["url" => "required|url"]);

		try {
			$downloadJob = $this->downloadService->startDownload(
				$request->url,
				\Auth::id()
			);

			logger()->info("Start downloading: " . $downloadJob->job_id);

			return $request->wantsJson()
				? response()->json([
					"success" => true,
					"job_id" => $downloadJob->job_id,
					"message" => "Download started in background",
				])
				: back()->with("success", "Download started in background.");
		} catch (\Exception $e) {
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
		return $this->eventStreamService->streamActiveDownloads($job_id);
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
	public function getActiveDownloads()
	{
		$user = \Auth::user();
		dd($user);

		$activeDownloads = method_exists($user, "activeDownloads")
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
