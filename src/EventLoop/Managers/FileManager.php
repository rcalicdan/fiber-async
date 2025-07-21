<?php

namespace Rcalicdan\FiberAsync\Managers;

use Rcalicdan\FiberAsync\EventLoop\IOHandlers\File\FileOperationHandler;
use Rcalicdan\FiberAsync\EventLoop\IOHandlers\File\FileWatcherHandler;
use Rcalicdan\FiberAsync\EventLoop\ValueObjects\FileOperation;
use Rcalicdan\FiberAsync\EventLoop\ValueObjects\FileWatcher;

class FileManager
{
    /** @var FileOperation[] */
    private array $pendingOperations = [];

    /** @var array<string, FileOperation> */
    private array $operationsById = [];

    /** @var FileWatcher[] */
    private array $watchers = [];

    /** @var array<string, FileWatcher> */
    private array $watchersById = [];

    private FileOperationHandler $operationHandler;
    private FileWatcherHandler $watcherHandler;

    public function __construct()
    {
        $this->operationHandler = new FileOperationHandler;
        $this->watcherHandler = new FileWatcherHandler;
    }

    public function addFileOperation(
        string $type,
        string $path,
        mixed $data,
        callable $callback,
        array $options = []
    ): string {
        $operation = $this->operationHandler->createOperation($type, $path, $data, $callback, $options);

        $this->pendingOperations[] = $operation;
        $this->operationsById[$operation->getId()] = $operation;

        return $operation->getId();
    }

    public function cancelFileOperation(string $operationId): bool
    {
        if (! isset($this->operationsById[$operationId])) {
            return false;
        }

        $operation = $this->operationsById[$operationId];
        $operation->cancel();

        // Remove from pending operations immediately
        $pendingKey = array_search($operation, $this->pendingOperations, true);
        if ($pendingKey !== false) {
            unset($this->pendingOperations[$pendingKey]);
            $this->pendingOperations = array_values($this->pendingOperations);
        }

        unset($this->operationsById[$operationId]);

        return true;
    }

    public function addFileWatcher(string $path, callable $callback, array $options = []): string
    {
        $watcher = $this->watcherHandler->createWatcher($path, $callback, $options);

        $this->watchers[] = $watcher;
        $this->watchersById[$watcher->getId()] = $watcher;

        return $watcher->getId();
    }

    public function removeFileWatcher(string $watcherId): bool
    {
        if (! isset($this->watchersById[$watcherId])) {
            return false;
        }

        unset($this->watchersById[$watcherId]);

        return $this->watcherHandler->removeWatcher($this->watchers, $watcherId);
    }

    public function processFileOperations(): bool
    {
        $workDone = false;

        // Process pending operations
        if ($this->processPendingOperations()) {
            $workDone = true;
        }

        // Process file watchers
        if ($this->processFileWatchers()) {
            $workDone = true;
        }

        return $workDone;
    }

    private function processPendingOperations(): bool
    {
        if (empty($this->pendingOperations)) {
            return false;
        }

        $processed = false;
        $operationsToProcess = $this->pendingOperations;
        $this->pendingOperations = [];

        foreach ($operationsToProcess as $operation) {
            // Skip cancelled operations entirely
            if ($operation->isCancelled()) {
                unset($this->operationsById[$operation->getId()]);

                continue;
            }

            if ($this->operationHandler->executeOperation($operation)) {
                $processed = true;
            }

            // Clean up from ID map
            unset($this->operationsById[$operation->getId()]);
        }

        return $processed;
    }

    private function processFileWatchers(): bool
    {
        return $this->watcherHandler->processWatchers($this->watchers);
    }

    public function hasWork(): bool
    {
        return ! empty($this->pendingOperations) || ! empty($this->watchers);
    }

    public function hasPendingOperations(): bool
    {
        return ! empty($this->pendingOperations);
    }

    public function hasWatchers(): bool
    {
        return ! empty($this->watchers);
    }
}
