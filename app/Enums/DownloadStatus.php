<?php

namespace Modules\Downloader\Enums;

enum DownloadStatus: string
{
	case PENDING = "pending";
	case DOWNLOADING = "downloading";
	case COMPLETED = "completed";
	case FAILED = "failed";
	case CANCELLED = "cancelled";
}
