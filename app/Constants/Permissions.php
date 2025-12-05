<?php
namespace Modules\Downloader\Constants;

class Permissions
{
	const VIEW_DOWNLOADERS = "downloader.view";
	const MANAGE_DOWNLOADS = "downloader.manage";

	public static function all(): array
	{
		return [
			self::VIEW_DOWNLOADERS => "View Downloader",
			self::MANAGE_DOWNLOADS => "Manage Downloader",
		];
	}
}
