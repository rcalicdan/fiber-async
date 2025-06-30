<?php
// src/Handlers/File/FileWatcherHandler.php

namespace Rcalicdan\FiberAsync\Handlers\File;

use Rcalicdan\FiberAsync\ValueObjects\FileWatcher;

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
        if (!$watcher->checkForChanges()) {
            return false;
        }

        $currentModified = filemtime($watcher->getPath());
        $watcher->updateLastModified($currentModified);
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