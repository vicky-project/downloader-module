<?php
namespace Modules\Downloader\Enums;

enum UrlType: string
{
	case DIRECT_FILE = "direct_file";
	case GOOGLE_DRIVE = "google_drive";
	case YOUTUBE = "youtube";
	case DROPBOX = "dropbox";
	case ONE_DRIVE = "one_drive";
	case OTHER = "other";

	public function label(): string
	{
		return match ($this) {
			self::DIRECT_FILE => "Direct File",
			self::GOOGLE_DRIVE => "Google Drive",
			self::YOUTUBE => "YouTube",
			self::DROPBOX => "Dropbox",
			self::ONE_DRIVE => "OneDrive",
			self::OTHER => "Other",
		};
	}
}
