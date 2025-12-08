<?php

namespace Modules\Downloader\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Downloader\Enums\DownloadStatus;

class DownloadQueue extends Model
{
	/**
	 * The attributes that are mass assignable.
	 */
	protected $fillable = [
		"download_id",
		"queue_job_id",
		"chunk_index",
		"start_byte",
		"end_byte",
		"downloaded_bytes",
		"status",
		"temp_file_path",
	];

	protected $casts = [
		"chunk_index" => "integer",
		"status" => DownloadStatus::class,
	];

	public function download()
	{
		return $this->belongsTo(Download::class);
	}
}
