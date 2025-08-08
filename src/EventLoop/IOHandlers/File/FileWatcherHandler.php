<?php

namespace Rcalicdan\FiberAsync\EventLoop\IOHandlers\File;

use Rcalicdan\FiberAsync\EventLoop\ValueObjects\FileWatcher;

/**
 * Manages file watchers: creation, polling, and removal.
 */
final readonly class FileWatcherHandler
{
    /**
     * Create a new FileWatcher.
     *
     * @param  string               $path      File or directory to watch
     * @param  callable             $callback  fn(string $event, string $path): void
     * @param  array<string,mixed>  $options   ['interval'=>int (ms), ...]
     * @return FileWatcher
     */
    public function createWatcher(
        string $path,
        callable $callback,
        array $options = []
    ): FileWatcher {
        return new FileWatcher($path, $callback, $options);
    }

    /**
     * Poll each watcher and invoke callbacks for those with changes.
     *
     * @param  FileWatcher[]  $watchers  List of watchers to process (by reference)
     * @return bool           True if any watcher detected changes
     */
    public function processWatchers(array &$watchers): bool
    {
        $processed = false;

        /** @var FileWatcher $watcher */
        foreach ($watchers as $watcher) {
            if ($this->checkWatcher($watcher)) {
                $processed = true;
            }
        }

        return $processed;
    }

    /**
     * Check a single watcher for changes and execute its callback if needed.
     *
     * @param  FileWatcher  $watcher
     * @return bool         True if callback executed
     */
    private function checkWatcher(FileWatcher $watcher): bool
    {
        if (! $watcher->shouldCheck()) {
            return false;
        }

        if (! $watcher->checkForChanges()) {
            return false;
        }

        $watcher->executeCallback('change', $watcher->getPath());
        return true;
    }

    /**
     * Remove a watcher by its unique ID.
     *
     * @param  FileWatcher[]  $watchers    List of watchers (by reference)
     * @param  string         $watcherId   The ID to remove
     * @return bool           True if removal succeeded
     */
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
