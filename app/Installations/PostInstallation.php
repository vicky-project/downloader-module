<?php
namespace Modules\Downloader\Installations;

use Nwidart\Modules\Facades\Module;
use Illuminate\Support\Facades\Artisan;

class PostInstallation
{
	public function handle(string $moduleName)
	{
		try {
			$module = Module::find($moduleName);
			$module->enable();

			Artisan::call("queue:table");
			Artisan::call("migrate", ["--force" => true]);
		} catch (\Exception $e) {
			logger()->error(
				"Failed to run post installation of {$module->getName()} module: " .
					$e->getMessage()
			);

			throw $e;
		}
	}
}
