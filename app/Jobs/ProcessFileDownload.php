<?php
namespace Modules\Downloader\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Downloader\Models\DownloadJob;
use Modules\Downloader\Enums\DownloadHandlerFactory;

class ProcessFileDownload implements ShouldQueue
{
	use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

	public $downloadJob;
	public $handlerName;
	public $timeout = 3600;
	public $tries = 3;

	public function __construct(DownloadJob $downloadJob, string $handlerName)
	{
		$this->downloadJob = $downloadJob;
		$this->handlerName = $handlerName;
		$this->onQueue("downloads");
	}

	public function handle(DownloadHandlerFactory $factory)
	{
		$this->downloadJob->update([
			"status" => DownloadStatus::DOWNLOADING,
			"metadata" => array_merge($this->downloadJob->metadata ?? [], [
				"job_started_at" => now()->toISOString(),
				"handler_name" => $this->handlerName,
			]),
		]);

		try {
			$handler = $factory->getHandlerByName($this->handlerName);
			if (!$handler) {
				throw new \Exception("Handler '{$this->handlerName}' not found");
			}

			$handler->download($this->downloadJob);
			$handler->download($this->downloadJob);

			$this->downloadJob->update([
				"status" => DownloadStatus::COMPLETED,
				"progress" => 100,
				"metadata" => array_merge($this->downloadJob->metadata ?? [], [
					"completed_at" => now()->toISOString(),
				]),
			]);
		} catch (\Exception $e) {
			$this->downloadJob->update([
				"status" => DownloadStatus::FAILED,
				"error_message" => $e->getMessage(),
				"metadata" => array_merge($this->downloadJob->metadata ?? [], [
					"failed_at" => now()->toISOString(),
					"error" => $e->getMessage(),
					"handler_error" => true,
				]),
			]);

			if ($this->attempts() < $this->tries) {
				$this->release(60);
			}
		}
	}

	public function failed(\Throwable $exception)
	{
		$this->downloadJob->update([
			"status" => DownloadStatus::FAILED,
			"error_message" => "Job failed: " . $exception->getMessage(),
			"metadata" => array_merge($this->downloadJob->metadata ?? [], [
				"job_failed_at" => now()->toISOString(),
				"job_exception" => $exception->getMessage(),
			]),
		]);
	}
}
