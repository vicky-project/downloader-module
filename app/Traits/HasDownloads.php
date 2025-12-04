<?php
namespace Modules\Downloader\Traits;

use Modules\Downloader\Models\DownloadJob;
use Illuminate\Database\Eloquent\Relations\HasMany;

trait HasDownloads
{
	/**
	 * Relationship dengan DownloadJob
	 */
	public function downloadJobs(): HasMany
	{
		return $this->hasMany(DownloadJob::class);
	}

	/**
	 * Get active downloads (in progress)
	 */
	public function activeDownloads(): HasMany
	{
		return $this->downloadJobs()->inProgress();
	}

	/**
	 * Get completed downloads
	 */
	public function completedDownloads(): HasMany
	{
		return $this->downloadJobs()->completed();
	}

	/**
	 * Get total download size for user
	 */
	public function getTotalDownloadSizeAttribute(): float
	{
		return optional(
			$this->downloadJobs()
				->completed()
				->sum("file_size")
		)->file_size ?? 0;
	}
}
