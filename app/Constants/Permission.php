<?php
namespace Modules\Downloader\Constants;

class Permission
{
	const VIEW_DOWNLOADER = "downloader.view";

	public static function all(): array
	{
		return [self::VIEW_DOWNLOADER => "View Downloader"];
	}
}
