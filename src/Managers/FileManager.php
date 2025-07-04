<?php

// src/Managers/FileManager.php

namespace Rcalicdan\FiberAsync\Managers;

use Rcalicdan\FiberAsync\Handlers\File\FileOperationHandler;
use Rcalicdan\FiberAsync\Handlers\File\FileWatcherHandler;
use Rcalicdan\FiberAsync\ValueObjects\FileOperation;
use Rcalicdan\FiberAsync\ValueObjects\FileWatcher;

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

    /**
     * Add a file operation and return a unique operation ID for cancellation
     */
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

    /**
     * Cancel a file operation by its ID
     */
    public function cancelFileOperation(string $operationId): bool
    {
        if (! isset($this->operationsById[$operationId])) {
            return false;
        }

        $operation = $this->operationsById[$operationId];

        // Remove from pending operations
        $pendingKey = array_search($operation, $this->pendingOperations, true);
        if ($pendingKey !== false) {
            unset($this->pendingOperations[$pendingKey]);
            $this->pendingOperations = array_values($this->pendingOperations);
        }

        unset($this->operationsById[$operationId]);

        // Notify callback of cancellation
        $operation->executeCallback('Operation cancelled');

        return true;
    }

    /**
     * Add a file watcher and return a unique watcher ID
     */
    public function addFileWatcher(string $path, callable $callback, array $options = []): string
    {
        $watcher = $this->watcherHandler->createWatcher($path, $callback, $options);

        $this->watchers[] = $watcher;
        $this->watchersById[$watcher->getId()] = $watcher;

        return $watcher->getId();
    }

    /**
     * Remove a file watcher by its ID
     */
    public function removeFileWatcher(string $watcherId): bool
    {
        if (! isset($this->watchersById[$watcherId])) {
            return false;
        }

        unset($this->watchersById[$watcherId]);

        return $this->watcherHandler->removeWatcher($this->watchers, $watcherId);
    }

    /**
     * Process pending file operations and watchers
     */
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
