<?php

namespace Rcalicdan\FiberAsync\File\Handlers;

use Rcalicdan\FiberAsync\EventLoop\EventLoop;
use Rcalicdan\FiberAsync\Promise\CancellablePromise;
use Rcalicdan\FiberAsync\Promise\Interfaces\CancellablePromiseInterface;

/**
 * Async file operations (non-blocking, cancellable).
 */
final readonly class FileHandler
{
    private EventLoop $eventLoop;

    /**
     * Constructor — attach to the global event loop.
     */
    public function __construct()
    {
        $this->eventLoop = EventLoop::getInstance();
    }

    /**
     * Read a file into memory asynchronously.
     *
     * @param  array<string,mixed>  $options
     * @return CancellablePromiseInterface<string>
     *
     * @throws \RuntimeException
     */
    public function readFile(string $path, array $options = []): CancellablePromiseInterface
    {
        /** @var CancellablePromise<string> $promise */
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
     * Open a file for streaming reads asynchronously.
     *
     * @param  array<string,mixed>  $options
     * @return CancellablePromiseInterface<resource>
     *
     * @throws \RuntimeException
     */
    public function readFileStream(string $path, array $options = []): CancellablePromiseInterface
    {
        /** @var CancellablePromise<resource> $promise */
        $promise = new CancellablePromise;
        $options['use_streaming'] = true;

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
     * Write data to a file using streaming mode (delegates to writeFile).
     *
     * @param  array<string,mixed>  $options
     * @return CancellablePromiseInterface<int>
     *
     * @throws \RuntimeException
     */
    public function writeFileStream(string $path, string $data, array $options = []): CancellablePromiseInterface
    {
        $options['use_streaming'] = true;

        return $this->writeFile($path, $data, $options);
    }

    /**
     * Copy a file using streaming operations asynchronously.
     *
     * @return CancellablePromiseInterface<bool>
     *
     * @throws \RuntimeException
     */
    public function copyFileStream(string $source, string $destination): CancellablePromiseInterface
    {
        /** @var CancellablePromise<bool> $promise */
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
            ['use_streaming' => true]
        );

        $promise->setCancelHandler(function () use ($operationId) {
            $this->eventLoop->cancelFileOperation($operationId);
        });

        return $promise;
    }

    /**
     * Write data to a file asynchronously.
     *
     * @param  array<string,mixed>  $options
     * @return CancellablePromiseInterface<int>
     *
     * @throws \RuntimeException
     */
    public function writeFile(string $path, string $data, array $options = []): CancellablePromiseInterface
    {
        /** @var CancellablePromise<int> $promise */
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

        $promise->setCancelHandler(function () use ($operationId) {
            $this->eventLoop->cancelFileOperation($operationId);
        });

        return $promise;
    }

    /**
     * Append data to a file asynchronously.
     *
     * @return CancellablePromiseInterface<int>
     *
     * @throws \RuntimeException
     */
    public function appendFile(string $path, string $data): CancellablePromiseInterface
    {
        /** @var CancellablePromise<int> $promise */
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

        $promise->setCancelHandler(function () use ($operationId) {
            $this->eventLoop->cancelFileOperation($operationId);
        });

        return $promise;
    }

    /**
     * Delete a file asynchronously.
     *
     * @return CancellablePromiseInterface<bool>
     *
     * @throws \RuntimeException
     */
    public function deleteFile(string $path): CancellablePromiseInterface
    {
        /** @var CancellablePromise<bool> $promise */
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

        $promise->setCancelHandler(function () use ($operationId) {
            $this->eventLoop->cancelFileOperation($operationId);
        });

        return $promise;
    }

    /**
     * Check existence of a path asynchronously.
     *
     * @return CancellablePromiseInterface<bool>
     *
     * @throws \RuntimeException
     */
    public function fileExists(string $path): CancellablePromiseInterface
    {
        /** @var CancellablePromise<bool> $promise */
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

        $promise->setCancelHandler(function () use ($operationId) {
            $this->eventLoop->cancelFileOperation($operationId);
        });

        return $promise;
    }

    /**
     * Get file stats asynchronously.
     *
     * @return CancellablePromiseInterface<array<string,mixed>>
     *
     * @throws \RuntimeException
     */
    public function getFileStats(string $path): CancellablePromiseInterface
    {
        /** @var CancellablePromise<array<string,mixed>> $promise */
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

        $promise->setCancelHandler(function () use ($operationId) {
            $this->eventLoop->cancelFileOperation($operationId);
        });

        return $promise;
    }

    /**
     * Create a directory asynchronously.
     *
     * @param  array<string,mixed>  $options
     * @return CancellablePromiseInterface<bool>
     *
     * @throws \RuntimeException
     */
    public function createDirectory(string $path, array $options = []): CancellablePromiseInterface
    {
        /** @var CancellablePromise<bool> $promise */
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

        $promise->setCancelHandler(function () use ($operationId) {
            $this->eventLoop->cancelFileOperation($operationId);
        });

        return $promise;
    }

    /**
     * Remove an empty directory asynchronously.
     *
     * @return CancellablePromiseInterface<bool>
     *
     * @throws \RuntimeException
     */
    public function removeDirectory(string $path): CancellablePromiseInterface
    {
        /** @var CancellablePromise<bool> $promise */
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

        $promise->setCancelHandler(function () use ($operationId) {
            $this->eventLoop->cancelFileOperation($operationId);
        });

        return $promise;
    }

    /**
     * Copy a file asynchronously.
     *
     * @return CancellablePromiseInterface<bool>
     *
     * @throws \RuntimeException
     */
    public function copyFile(string $source, string $destination): CancellablePromiseInterface
    {
        /** @var CancellablePromise<bool> $promise */
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

        $promise->setCancelHandler(function () use ($operationId) {
            $this->eventLoop->cancelFileOperation($operationId);
        });

        return $promise;
    }

    /**
     * Rename or move a file asynchronously.
     *
     * @return CancellablePromiseInterface<bool>
     *
     * @throws \RuntimeException
     */
    public function renameFile(string $oldPath, string $newPath): CancellablePromiseInterface
    {
        /** @var CancellablePromise<bool> $promise */
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

        $promise->setCancelHandler(function () use ($operationId) {
            $this->eventLoop->cancelFileOperation($operationId);
        });

        return $promise;
    }

    /**
     * Watch a file or directory for changes.
     *
     * @param  array<string,mixed>  $options
     * @return string Watcher ID
     *
     * @throws \RuntimeException
     */
    public function watchFile(string $path, callable $callback, array $options = []): string
    {
        return $this->eventLoop->addFileWatcher($path, $callback, $options);
    }

    /**
     * Stop watching by watcher ID.
     */
    public function unwatchFile(string $watcherId): bool
    {
        return $this->eventLoop->removeFileWatcher($watcherId);
    }
}
