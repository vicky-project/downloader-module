<?php
namespace Modules\Downloader\Constants;

class Permissions
{
	const VIEW_DOWNLOADERS = "downloader.view";

	public static function all(): array
	{
		return [self::VIEW_DOWNLOADERS => "View Downloader"];
	}
}
