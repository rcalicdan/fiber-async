<?php

namespace Rcalicdan\FiberAsync\File\Handlers;

use Rcalicdan\FiberAsync\EventLoop\EventLoop;
use Rcalicdan\FiberAsync\Promise\CancellablePromise;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

final readonly class FileHandler
{
    private EventLoop $eventLoop;

    public function __construct()
    {
        $this->eventLoop = EventLoop::getInstance();
    }

    public function readFile(string $path, array $options = []): PromiseInterface
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

                if ($error) {
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

    public function readFileStream(string $path, array $options = []): PromiseInterface
    {
        $options['use_streaming'] = true;

        return $this->readFile($path, $options);
    }

    public function writeFileStream(string $path, string $data, array $options = []): PromiseInterface
    {
        $options['use_streaming'] = true;

        return $this->writeFile($path, $data, $options);
    }

    public function copyFileStream(string $source, string $destination): PromiseInterface
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

                if ($error) {
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

    public function writeFile(string $path, string $data, array $options = []): PromiseInterface
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

                if ($error) {
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

    public function appendFile(string $path, string $data): PromiseInterface
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

                if ($error) {
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

    public function deleteFile(string $path): PromiseInterface
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

                if ($error) {
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

    public function fileExists(string $path): PromiseInterface
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

                if ($error) {
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

    public function getFileStats(string $path): PromiseInterface
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

                if ($error) {
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

    public function createDirectory(string $path, array $options = []): PromiseInterface
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

                if ($error) {
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

    public function removeDirectory(string $path): PromiseInterface
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

                if ($error) {
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

    public function copyFile(string $source, string $destination): PromiseInterface
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

                if ($error) {
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

    public function renameFile(string $oldPath, string $newPath): PromiseInterface
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

                if ($error) {
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

    public function watchFile(string $path, callable $callback, array $options = []): string
    {
        return $this->eventLoop->addFileWatcher($path, $callback, $options);
    }

    public function unwatchFile(string $watcherId): bool
    {
        return $this->eventLoop->removeFileWatcher($watcherId);
    }
}
