<?php

namespace Modules\Downloader\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\User;
use Modules\Downloader\Enums\DownloadStatus;
use Illuminate\Support\Number;

class DownloadJob extends Model
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
		"original_filename",
		"file_type",
		"file_size",
		"progress",
		"status",
		"error_message",
		"local_path",
		"metadata",
	];

	protected $casts = [
		"metadata" => "array",
		"file_size" => "decimal:2",
		"status" => DownloadStatus::class,
	];

	/**
	 * Relationship dengan User
	 */
	public function user(): BelongsTo
	{
		return $this->belongsTo(User::class);
	}

	/**
	 * Scope untuk download milik user tertentu
	 */
	public function scopeOwnedBy($query, $userId)
	{
		return $query->where("user_id", $userId);
	}

	/**
	 * Scope untuk download yang sedang berjalan
	 */
	public function scopeInProgress($query)
	{
		return $query->whereIn("status", [
			DownloadStatus::PENDING,
			DownloadStatus::DOWNLOADING,
		]);
	}

	/**
	 * Scope untuk download yang selesai
	 */
	public function scopeCompleted($query)
	{
		return $query->where("status", DownloadStatus::COMPLETED);
	}

	/**
	 * Cek apakah user adalah pemilik download
	 */
	public function isOwnedBy($userId): bool
	{
		return $this->user_id == $userId;
	}

	/**
	 * Format file size untuk display
	 */
	public function getFormattedFileSizeAttribute(): string
	{
		if (!$this->file_size) {
			return "Unknown";
		}

		return Number::fileSize($this->file_size);
	}
}
