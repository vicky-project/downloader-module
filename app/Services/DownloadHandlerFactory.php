<?php
namespace Modules\Downloader\Services;

use Illuminate\Support\Collection;
use Modules\Downloader\Contracts\DownloadHandlerInterface;
use Modules\Downloader\Enums\UrlType;
use Modules\Downloader\Services\Handlers\{
	DirectFileHandler,
	GoogleDriveHandler,
	YouTubeHandler,
	DropboxHandler,
	OneDriveHandler,
	GenericUrlHandler
};

class DownloadHandlerFactory
{
	protected Collection $handlers;
	protected array $handlerClasses = [
		DirectFileHandler::class,
		GoogleDriveHandler::class,
		YouTubeHandler::class,
		DropboxHandler::class,
		OneDriveHandler::class,
		GenericUrlHandler::class,
	];

	public function __construct()
	{
		$this->handlers = collect();
		$this->registerHandlers();
	}

	/**
	 * Register all available handlers
	 */
	protected function registerHandlers(): void
	{
		foreach ($this->handlerClasses as $handlerClass) {
			$this->registerHandler(app($handlerClass));
		}
	}

	/**
	 * Register a single handler
	 */
	public function registerHandler(DownloadHandlerInterface $handler): void
	{
		$this->handlers->push($handler);
	}

	/**
	 * Get handler for URL type
	 */
	public function getHandlerForType(UrlType $urlType): DownloadHandlerInterface
	{
		// Sort by priority (highest first)
		$sortedHandlers = $this->handlers->sortByDesc(function ($handler) {
			return $handler->getPriority();
		});

		// Find first handler that supports the URL type
		foreach ($sortedHandlers as $handler) {
			if ($handler->supports($urlType)) {
				return $handler;
			}
		}

		// Fallback to generic handler
		return app(GenericUrlHandler::class);
	}

	/**
	 * Get handler by name
	 */
	public function getHandlerByName(string $name): ?DownloadHandlerInterface
	{
		return $this->handlers->first(function ($handler) use ($name) {
			return $handler->getName() === $name;
		});
	}

	/**
	 * Get all registered handlers
	 */
	public function getAllHandlers(): Collection
	{
		return $this->handlers->sortByDesc(function ($handler) {
			return $handler->getPriority();
		});
	}

	/**
	 * Check if URL type is supported
	 */
	public function isSupported(UrlType $urlType): bool
	{
		return $this->handlers->contains(function ($handler) use ($urlType) {
			return $handler->supports($urlType);
		});
	}

	/**
	 * Get supported URL types
	 */
	public function getSupportedTypes(): array
	{
		$types = [];

		foreach (UrlType::cases() as $urlType) {
			if ($this->isSupported($urlType)) {
				$types[] = [
					"type" => $urlType,
					"label" => $urlType->label(),
				];
			}
		}

		return $types;
	}
}
