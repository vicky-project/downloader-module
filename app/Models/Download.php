<?php

namespace Modules\Downloader\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\User;
use Modules\Downloader\Enums\DownloadStatus;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

class Download extends Model
{
	protected $fillable = [
		"user_id",
		"job_id",
		"filename",
		"original_filename",
		"url",
		"type",
		"status",
		"progress",
		"total_size",
		"downloaded_size",
		"speed",
		"time_remaining",
		"metadata",
		"error_message",
		"file_path",
		"mime_type",
		"started_at",
		"completed_at",
	];

	protected $casts = [
		"metadata" => "array",
		"status" => DownloadStatus::class,
		"progress" => "decimal:2",
		"total_size" => "integer",
		"downloaded_size" => "integer",
		"speed" => "integer",
		"time_remaining" => "integer",
		"started_at" => "datetime",
		"completed_at" => "datetime",
	];

	public function user()
	{
		return $this->belongsTo(\App\Models\User::class);
	}

	public function isResumable()
	{
		return in_array($this->type, ["direct", "google_drive"]);
	}

	public function canPause()
	{
		return $this->isResumable() &&
			$this->status === DownloadStatus::DOWNLOADING;
	}

	public function getFormattedSize()
	{
		if (!$this->total_size) {
			return "Unknown";
		}

		return Number::fileSize($this->total_size);
	}
}
