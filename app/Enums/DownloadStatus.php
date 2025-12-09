<?php

namespace Modules\Downloader\Enums;

enum DownloadStatus: string
{
	case PENDING = "pending";
	case DOWNLOADING = "downloading";
	case PROCESSING = "processing";
	case COMPLETED = "completed";
	case FAILED = "failed";
	case CANCELLED = "cancelled";
	case PAUSED = "paused";
}
