<?php

namespace Rcalicdan\FiberAsync\File\Handlers;

use Rcalicdan\FiberAsync\EventLoop\EventLoop;
use Rcalicdan\FiberAsync\Promise\CancellablePromise;
use Rcalicdan\FiberAsync\Promise\Interfaces\CancellablePromiseInterface;

/**
 * Handles asynchronous file operations using the event loop.
 */
final readonly class FileHandler
{
    /**
     * @var EventLoop The event loop instance for managing async operations.
     */
    private EventLoop $eventLoop;

    /**
     * Create a new FileHandler instance.
     */
    public function __construct()
    {
        $this->eventLoop = EventLoop::getInstance();
    }

    /**
     * Read a file asynchronously.
     *
     * @param string $path The file path to read.
     * @param array<string, mixed> $options Additional options for the read operation.
     * @return CancellablePromiseInterface<string> A promise that resolves with the file contents.
     */
    public function readFile(string $path, array $options = []): CancellablePromiseInterface
    {
        $promise = new CancellablePromise;

        $operationId = $this->eventLoop->addFileOperation(
            'read',
            $path,
            null,
            function (?string $error, mixed $result = null) use ($promise) {
                if ($promise->isCancelled()) {
                    return;
                }

                if ($error !== null) {
                    $promise->reject(new \RuntimeException($error));
                } else {
                    $promise->resolve($result);
                }
            },
            $options
        );

        $promise->setCancelHandler(function () use ($operationId) {
            $this->eventLoop->cancelFileOperation($operationId);
        });

        return $promise;
    }

    /**
     * Read a file using streaming asynchronously.
     *
     * @param string $path The file path to read.
     * @param array<string, mixed> $options Additional options for the read operation.
     * @return CancellablePromiseInterface<resource> A promise that resolves with the file stream resource.
     */
    public function readFileStream(string $path, array $options = []): CancellablePromiseInterface
    {
        $options['use_streaming'] = true;

        return $this->readFile($path, $options);
    }

    /**
     * Write to a file using streaming asynchronously.
     *
     * @param string $path The file path to write to.
     * @param string $data The data to write.
     * @param array<string, mixed> $options Additional options for the write operation.
     * @return CancellablePromiseInterface<int> A promise that resolves with the number of bytes written.
     */
    public function writeFileStream(string $path, string $data, array $options = []): CancellablePromiseInterface
    {
        $options['use_streaming'] = true;

        return $this->writeFile($path, $data, $options);
    }

    /**
     * Copy a file using streaming asynchronously.
     *
     * @param string $source The source file path.
     * @param string $destination The destination file path.
     * @return CancellablePromiseInterface<bool> A promise that resolves with true when the copy is complete.
     */
    public function copyFileStream(string $source, string $destination): CancellablePromiseInterface
    {
        $promise = new CancellablePromise;

        $operationId = $this->eventLoop->addFileOperation(
            'copy',
            $source,
            $destination,
            function (?string $error, mixed $result = null) use ($promise) {
                if ($promise->isCancelled()) {
                    return;
                }

                if ($error !== null) {
                    $promise->reject(new \RuntimeException($error));
                } else {
                    $promise->resolve($result);
                }
            },
            ['use_streaming' => true] // Force streaming for copy operations
        );

        $promise->setCancelHandler(function () use ($operationId) {
            $this->eventLoop->cancelFileOperation($operationId);
        });

        return $promise;
    }

    /**
     * Write to a file asynchronously.
     *
     * @param string $path The file path to write to.
     * @param string $data The data to write.
     * @param array<string, mixed> $options Additional options for the write operation.
     * @return CancellablePromiseInterface<int> A promise that resolves with the number of bytes written.
     */
    public function writeFile(string $path, string $data, array $options = []): CancellablePromiseInterface
    {
        $promise = new CancellablePromise;

        $operationId = $this->eventLoop->addFileOperation(
            'write',
            $path,
            $data,
            function (?string $error, mixed $result = null) use ($promise) {
                if ($promise->isCancelled()) {
                    return;
                }

                if ($error !== null) {
                    $promise->reject(new \RuntimeException($error));
                } else {
                    $promise->resolve($result);
                }
            },
            $options
        );

        // Set up cancellation handler
        $promise->setCancelHandler(function () use ($operationId) {
            $this->eventLoop->cancelFileOperation($operationId);
        });

        return $promise;
    }

    /**
     * Append data to a file asynchronously.
     *
     * @param string $path The file path to append to.
     * @param string $data The data to append.
     * @return CancellablePromiseInterface<int> A promise that resolves with the number of bytes written.
     */
    public function appendFile(string $path, string $data): CancellablePromiseInterface
    {
        $promise = new CancellablePromise;

        $operationId = $this->eventLoop->addFileOperation(
            'append',
            $path,
            $data,
            function (?string $error, mixed $result = null) use ($promise) {
                if ($promise->isCancelled()) {
                    return;
                }

                if ($error !== null) {
                    $promise->reject(new \RuntimeException($error));
                } else {
                    $promise->resolve($result);
                }
            }
        );

        // Set up cancellation handler
        $promise->setCancelHandler(function () use ($operationId) {
            $this->eventLoop->cancelFileOperation($operationId);
        });

        return $promise;
    }

    /**
     * Delete a file asynchronously.
     *
     * @param string $path The file path to delete.
     * @return CancellablePromiseInterface<bool> A promise that resolves with true when the deletion is complete.
     */
    public function deleteFile(string $path): CancellablePromiseInterface
    {
        $promise = new CancellablePromise;

        $operationId = $this->eventLoop->addFileOperation(
            'delete',
            $path,
            null,
            function (?string $error, mixed $result = null) use ($promise) {
                if ($promise->isCancelled()) {
                    return;
                }

                if ($error !== null) {
                    $promise->reject(new \RuntimeException($error));
                } else {
                    $promise->resolve($result);
                }
            }
        );

        // Set up cancellation handler
        $promise->setCancelHandler(function () use ($operationId) {
            $this->eventLoop->cancelFileOperation($operationId);
        });

        return $promise;
    }

    /**
     * Check if a file exists asynchronously.
     *
     * @param string $path The file path to check.
     * @return CancellablePromiseInterface<bool> A promise that resolves with true if the file exists, false otherwise.
     */
    public function fileExists(string $path): CancellablePromiseInterface
    {
        $promise = new CancellablePromise;

        $operationId = $this->eventLoop->addFileOperation(
            'exists',
            $path,
            null,
            function (?string $error, mixed $result = null) use ($promise) {
                if ($promise->isCancelled()) {
                    return;
                }

                if ($error !== null) {
                    $promise->reject(new \RuntimeException($error));
                } else {
                    $promise->resolve($result);
                }
            }
        );

        // Set up cancellation handler
        $promise->setCancelHandler(function () use ($operationId) {
            $this->eventLoop->cancelFileOperation($operationId);
        });

        return $promise;
    }

    /**
     * Get file statistics asynchronously.
     *
     * @param string $path The file path to get stats for.
     * @return CancellablePromiseInterface<array<string, mixed>> A promise that resolves with the file statistics array.
     */
    public function getFileStats(string $path): CancellablePromiseInterface
    {
        $promise = new CancellablePromise;

        $operationId = $this->eventLoop->addFileOperation(
            'stat',
            $path,
            null,
            function (?string $error, mixed $result = null) use ($promise) {
                if ($promise->isCancelled()) {
                    return;
                }

                if ($error !== null) {
                    $promise->reject(new \RuntimeException($error));
                } else {
                    $promise->resolve($result);
                }
            }
        );

        // Set up cancellation handler
        $promise->setCancelHandler(function () use ($operationId) {
            $this->eventLoop->cancelFileOperation($operationId);
        });

        return $promise;
    }

    /**
     * Create a directory asynchronously.
     *
     * @param string $path The directory path to create.
     * @param array<string, mixed> $options Additional options for the directory creation.
     * @return CancellablePromiseInterface<bool> A promise that resolves with true when the directory is created.
     */
    public function createDirectory(string $path, array $options = []): CancellablePromiseInterface
    {
        $promise = new CancellablePromise;

        $operationId = $this->eventLoop->addFileOperation(
            'mkdir',
            $path,
            null,
            function (?string $error, mixed $result = null) use ($promise) {
                if ($promise->isCancelled()) {
                    return;
                }

                if ($error !== null) {
                    $promise->reject(new \RuntimeException($error));
                } else {
                    $promise->resolve($result);
                }
            },
            $options
        );

        // Set up cancellation handler
        $promise->setCancelHandler(function () use ($operationId) {
            $this->eventLoop->cancelFileOperation($operationId);
        });

        return $promise;
    }

    /**
     * Remove a directory asynchronously.
     *
     * @param string $path The directory path to remove.
     * @return CancellablePromiseInterface<bool> A promise that resolves with true when the directory is removed.
     */
    public function removeDirectory(string $path): CancellablePromiseInterface
    {
        $promise = new CancellablePromise;

        $operationId = $this->eventLoop->addFileOperation(
            'rmdir',
            $path,
            null,
            function (?string $error, mixed $result = null) use ($promise) {
                if ($promise->isCancelled()) {
                    return;
                }

                if ($error !== null) {
                    $promise->reject(new \RuntimeException($error));
                } else {
                    $promise->resolve($result);
                }
            }
        );

        // Set up cancellation handler
        $promise->setCancelHandler(function () use ($operationId) {
            $this->eventLoop->cancelFileOperation($operationId);
        });

        return $promise;
    }

    /**
     * Copy a file asynchronously.
     *
     * @param string $source The source file path.
     * @param string $destination The destination file path.
     * @return CancellablePromiseInterface<bool> A promise that resolves with true when the copy is complete.
     */
    public function copyFile(string $source, string $destination): CancellablePromiseInterface
    {
        $promise = new CancellablePromise;

        $operationId = $this->eventLoop->addFileOperation(
            'copy',
            $source,
            $destination,
            function (?string $error, mixed $result = null) use ($promise) {
                if ($promise->isCancelled()) {
                    return;
                }

                if ($error !== null) {
                    $promise->reject(new \RuntimeException($error));
                } else {
                    $promise->resolve($result);
                }
            }
        );

        // Set up cancellation handler
        $promise->setCancelHandler(function () use ($operationId) {
            $this->eventLoop->cancelFileOperation($operationId);
        });

        return $promise;
    }

    /**
     * Rename/move a file asynchronously.
     *
     * @param string $oldPath The current file path.
     * @param string $newPath The new file path.
     * @return CancellablePromiseInterface<bool> A promise that resolves with true when the rename is complete.
     */
    public function renameFile(string $oldPath, string $newPath): CancellablePromiseInterface
    {
        $promise = new CancellablePromise;

        $operationId = $this->eventLoop->addFileOperation(
            'rename',
            $oldPath,
            $newPath,
            function (?string $error, mixed $result = null) use ($promise) {
                if ($promise->isCancelled()) {
                    return;
                }

                if ($error !== null) {
                    $promise->reject(new \RuntimeException($error));
                } else {
                    $promise->resolve($result);
                }
            }
        );

        // Set up cancellation handler
        $promise->setCancelHandler(function () use ($operationId) {
            $this->eventLoop->cancelFileOperation($operationId);
        });

        return $promise;
    }

    /**
     * Watch a file for changes.
     *
     * @param string $path The file path to watch.
     * @param callable $callback The callback to execute when the file changes.
     * @param array<string, mixed> $options Additional options for the file watcher.
     * @return string The watcher ID for later removal.
     */
    public function watchFile(string $path, callable $callback, array $options = []): string
    {
        return $this->eventLoop->addFileWatcher($path, $callback, $options);
    }

    /**
     * Stop watching a file.
     *
     * @param string $watcherId The watcher ID to remove.
     * @return bool True if the watcher was successfully removed, false otherwise.
     */
    public function unwatchFile(string $watcherId): bool
    {
        return $this->eventLoop->removeFileWatcher($watcherId);
    }
}