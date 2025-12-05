<?php
namespace Modules\Downloader\Providers;

use Modules\MenuManagement\Interfaces\MenuProviderInterface;
use Modules\Financial\Constants\Permission;

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
				"role" => "user",
				"route" => "downloader.index",
				"permission" => Permission::VIEW_DOWNLOADER,
			],
		];
	}
}
