<?php

// src/Handlers/AsyncOperations/FileHandler.php

namespace Rcalicdan\FiberAsync\Handlers\AsyncOperations;

use Rcalicdan\FiberAsync\AsyncEventLoop;
use Rcalicdan\FiberAsync\AsyncPromise;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;

final readonly class FileHandler
{
    private AsyncEventLoop $eventLoop;

    public function __construct()
    {
        $this->eventLoop = AsyncEventLoop::getInstance();
    }

    public function readFile(string $path, array $options = []): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) use ($path, $options) {
            $this->eventLoop->addFileOperation(
                'read',
                $path,
                null,
                function (?string $error, mixed $result = null) use ($resolve, $reject) {
                    if ($error) {
                        $reject(new \RuntimeException($error));
                    } else {
                        $resolve($result);
                    }
                },
                $options
            );
        });
    }

    public function writeFile(string $path, string $data, array $options = []): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) use ($path, $data, $options) {
            $this->eventLoop->addFileOperation(
                'write',
                $path,
                $data,
                function (?string $error, mixed $result = null) use ($resolve, $reject) {
                    if ($error) {
                        $reject(new \RuntimeException($error));
                    } else {
                        $resolve($result);
                    }
                },
                $options
            );
        });
    }

    public function appendFile(string $path, string $data): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) use ($path, $data) {
            $this->eventLoop->addFileOperation(
                'append',
                $path,
                $data,
                function (?string $error, mixed $result = null) use ($resolve, $reject) {
                    if ($error) {
                        $reject(new \RuntimeException($error));
                    } else {
                        $resolve($result);
                    }
                }
            );
        });
    }

    public function deleteFile(string $path): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) use ($path) {
            $this->eventLoop->addFileOperation(
                'delete',
                $path,
                null,
                function (?string $error, mixed $result = null) use ($resolve, $reject) {
                    if ($error) {
                        $reject(new \RuntimeException($error));
                    } else {
                        $resolve($result);
                    }
                }
            );
        });
    }

    public function fileExists(string $path): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) use ($path) {
            $this->eventLoop->addFileOperation(
                'exists',
                $path,
                null,
                function (?string $error, mixed $result = null) use ($resolve, $reject) {
                    if ($error) {
                        $reject(new \RuntimeException($error));
                    } else {
                        $resolve($result);
                    }
                }
            );
        });
    }

    public function getFileStats(string $path): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) use ($path) {
            $this->eventLoop->addFileOperation(
                'stat',
                $path,
                null,
                function (?string $error, mixed $result = null) use ($resolve, $reject) {
                    if ($error) {
                        $reject(new \RuntimeException($error));
                    } else {
                        $resolve($result);
                    }
                }
            );
        });
    }

    public function createDirectory(string $path, array $options = []): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) use ($path, $options) {
            $this->eventLoop->addFileOperation(
                'mkdir',
                $path,
                null,
                function (?string $error, mixed $result = null) use ($resolve, $reject) {
                    if ($error) {
                        $reject(new \RuntimeException($error));
                    } else {
                        $resolve($result);
                    }
                },
                $options
            );
        });
    }

    public function removeDirectory(string $path): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) use ($path) {
            $this->eventLoop->addFileOperation(
                'rmdir',
                $path,
                null,
                function (?string $error, mixed $result = null) use ($resolve, $reject) {
                    if ($error) {
                        $reject(new \RuntimeException($error));
                    } else {
                        $resolve($result);
                    }
                }
            );
        });
    }

    public function copyFile(string $source, string $destination): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) use ($source, $destination) {
            $this->eventLoop->addFileOperation(
                'copy',
                $source,
                $destination,
                function (?string $error, mixed $result = null) use ($resolve, $reject) {
                    if ($error) {
                        $reject(new \RuntimeException($error));
                    } else {
                        $resolve($result);
                    }
                }
            );
        });
    }

    public function renameFile(string $oldPath, string $newPath): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) use ($oldPath, $newPath) {
            $this->eventLoop->addFileOperation(
                'rename',
                $oldPath,
                $newPath,
                function (?string $error, mixed $result = null) use ($resolve, $reject) {
                    if ($error) {
                        $reject(new \RuntimeException($error));
                    } else {
                        $resolve($result);
                    }
                }
            );
        });
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
