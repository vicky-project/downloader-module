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
	use HasFactory;

	/**
	 * The attributes that are mass assignable.
	 */
	protected $fillable = [
		"user_id",
		"job_id",
		"url",
		"filename",
		"file_extension",
		"mime_type",
		"file_size",
		"downloaded_size",
		"status",
		"connections",
		"progress",
		"download_speed",
		"metadata",
		"save_path",
		"error_message",
		"resume_info",
		"started_at",
		"completed_at",
	];

	protected $casts = [
		"metadata" => "array",
		"resume_info" => "array",
		"file_size" => "integer",
		"downloaded_size" => "integer",
		"progress" => "float",
		"download_speed" => "float",
		"status" => DownloadStatus::class,
		"started_at" => "datetime",
		"completed_at" => "datetime",
	];

	protected static function boot()
	{
		parent::boot();

		static::creating(function ($model) {
			if (empty($model->job_id)) {
				$model->job_id = Str::uuid()->toString();
			}
		});
	}

	/**
	 * Relationship dengan User
	 */
	public function user(): BelongsTo
	{
		return $this->belongsTo(User::class);
	}

	public function queues()
	{
		return $this->hasMany(DownloadQueue::class);
	}
}
