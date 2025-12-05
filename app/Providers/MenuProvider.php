<?php
namespace Modules\Downloader\Providers;

use Modules\MenuManagement\Interfaces\MenuProviderInterface;
use Modules\Downloader\Constants\Permissions;

class MenuProvider implements MenuProviderInterface
{
	/**
	 * Get Menu for LogManagement Module.
	 */
	public static function getMenus(): array
	{
		return [
			[
				"id" => "downloader",
				"name" => "Downloader",
				"order" => 20,
				"icon" => "cloud-download",
				"role" => ["super-admin", "admin", "user"],
				"route" => "downloader.index",
				"permission" => Permissions::VIEW_DOWNLOADERS,
			],
		];
	}
}
