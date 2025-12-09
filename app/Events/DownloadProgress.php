<?php

namespace Modules\Downloader\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DownloadProgress implements ShouldBroadcast
{
	use Dispatchable, InteractsWithSockets, SerializesModels;

	public $jobId;
	public $progress;
	public $downloaded;
	public $total;
	public $timestamp;

	public function __construct(
		string $jobId,
		float $progress,
		int $downloaded,
		?int $total = null
	) {
		$this->jobId = $jobId;
		$this->progress = $progress;
		$this->downloaded = $downloaded;
		$this->total = $total;
		$this->timestamp = now()->toISOString();
	}

	public function broadcastOn()
	{
		// Broadcast to private channel for job ID
		return new Channel("download.{$this->jobId}");
	}

	public function broadcastAs()
	{
		return "download.progress";
	}

	public function broadcastWith()
	{
		return [
			"job_id" => $this->jobId,
			"progress" => $this->progress,
			"downloaded" => $this->downloaded,
			"total" => $this->total,
			"timestamp" => $this->timestamp,
		];
	}
}
