<?php

// src/Handlers/File/FileWatcherHandler.php

namespace Rcalicdan\FiberAsync\EventLoop\IOHandlers\File;

use Rcalicdan\FiberAsync\EventLoop\ValueObjects\FileWatcher;

final readonly class FileWatcherHandler
{
    public function createWatcher(string $path, callable $callback, array $options = []): FileWatcher
    {
        return new FileWatcher($path, $callback, $options);
    }

    public function processWatchers(array &$watchers): bool
    {
        $processed = false;

        foreach ($watchers as $watcher) {
            if ($this->checkWatcher($watcher)) {
                $processed = true;
            }
        }

        return $processed;
    }

    private function checkWatcher(FileWatcher $watcher): bool
    {
        // Check if enough time has passed for polling
        if (! $watcher->shouldCheck()) {
            return false;
        }

        if (! $watcher->checkForChanges()) {
            return false;
        }

        // Execute callback when changes are detected
        $watcher->executeCallback('change', $watcher->getPath());

        return true;
    }

    public function removeWatcher(array &$watchers, string $watcherId): bool
    {
        foreach ($watchers as $key => $watcher) {
            if ($watcher->getId() === $watcherId) {
                unset($watchers[$key]);

                return true;
            }
        }

        return false;
    }
}
