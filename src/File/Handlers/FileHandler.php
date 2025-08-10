<?php

namespace Rcalicdan\FiberAsync\File\Handlers;

use Rcalicdan\FiberAsync\EventLoop\EventLoop;
use Rcalicdan\FiberAsync\Promise\CancellablePromise;
use Rcalicdan\FiberAsync\Promise\Interfaces\CancellablePromiseInterface;

/**
 * Asynchronous file handler for non-blocking file operations.
 *
 * This class provides a comprehensive set of asynchronous file operations including
 * reading, writing, copying, renaming, and directory management. All operations
 * return cancellable promises that integrate with the fiber-based event loop system.
 *
 * Features:
 * - Non-blocking file I/O operations
 * - Streaming support for large files
 * - Cancellable operations with resource cleanup
 * - File system watching capabilities
 * - Promise-based API with proper error handling
 *
 * @package Rcalicdan\FiberAsync\File\Handlers
 */
final readonly class FileHandler
{
    private EventLoop $eventLoop;

    /**
     * Initialize the file handler with event loop integration.
     */
    public function __construct()
    {
        $this->eventLoop = EventLoop::getInstance();
    }

    /**
     * Asynchronously read the entire contents of a file.
     *
     * This method reads a file completely into memory and returns the contents
     * as a string. For large files, consider using readFileStream() instead.
     *
     * @param string $path The path to the file to read
     * @param array<string, mixed> $options Optional configuration options:
     *   - 'encoding' => string: Character encoding (default: 'utf-8')
     *   - 'offset' => int: Starting position to read from
     *   - 'length' => int: Maximum bytes to read
     *   - 'flags' => int: File operation flags
     * @return CancellablePromiseInterface<string> Promise that resolves with file contents
     * @throws \RuntimeException If the file cannot be read or doesn't exist
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
     * Asynchronously open a file for streaming read operations.
     *
     * This method returns a file resource/stream handle that can be used for
     * reading large files without loading the entire content into memory.
     * The stream should be properly closed when done.
     *
     * @param string $path The path to the file to open for streaming
     * @param array<string, mixed> $options Optional configuration options:
     *   - 'mode' => string: File open mode (default: 'r')
     *   - 'buffer_size' => int: Read buffer size in bytes
     *   - 'context' => resource: Stream context resource
     * @return CancellablePromiseInterface<resource> Promise that resolves with file stream resource
     * @throws \RuntimeException If the file cannot be opened for reading
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
     * Asynchronously write data to a file using streaming mode.
     *
     * This method writes data to a file using streaming operations, which is
     * more memory efficient for large amounts of data. The file will be created
     * if it doesn't exist, or truncated if it does exist.
     *
     * @param string $path The path where the file should be written
     * @param string $data The data to write to the file
     * @param array<string, mixed> $options Optional configuration options:
     *   - 'mode' => string: File write mode (default: 'w')
     *   - 'buffer_size' => int: Write buffer size in bytes
     *   - 'create_dirs' => bool: Create parent directories if they don't exist
     * @return CancellablePromiseInterface<int> Promise that resolves with number of bytes written
     * @throws \RuntimeException If the file cannot be written or directory cannot be created
     */
    public function writeFileStream(string $path, string $data, array $options = []): CancellablePromiseInterface
    {
        $options['use_streaming'] = true;

        return $this->writeFile($path, $data, $options);
    }

    /**
     * Asynchronously copy a file using streaming operations.
     *
     * This method copies a file from source to destination using streaming
     * operations to handle large files efficiently without loading the entire
     * file into memory. Parent directories will be created if necessary.
     *
     * @param string $source The path to the source file to copy
     * @param string $destination The path where the file should be copied to
     * @return CancellablePromiseInterface<bool> Promise that resolves with true on successful copy
     * @throws \RuntimeException If the source file doesn't exist or copy operation fails
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
            ['use_streaming' => true] // Force streaming for copy operations
        );

        $promise->setCancelHandler(function () use ($operationId) {
            $this->eventLoop->cancelFileOperation($operationId);
        });

        return $promise;
    }

    /**
     * Asynchronously write data to a file.
     *
     * This method writes the provided data to a file, creating the file if it
     * doesn't exist or overwriting it if it does. For large amounts of data,
     * consider using writeFileStream() for better memory efficiency.
     *
     * @param string $path The path where the file should be written
     * @param string $data The data to write to the file
     * @param array<string, mixed> $options Optional configuration options:
     *   - 'mode' => string: File write mode (default: 'w')
     *   - 'permissions' => int: File permissions in octal format
     *   - 'create_dirs' => bool: Create parent directories if they don't exist
     *   - 'lock' => bool: Use file locking during write operation
     * @return CancellablePromiseInterface<int> Promise that resolves with number of bytes written
     * @throws \RuntimeException If the file cannot be written or directory cannot be created
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

        // Set up cancellation handler
        $promise->setCancelHandler(function () use ($operationId) {
            $this->eventLoop->cancelFileOperation($operationId);
        });

        return $promise;
    }

    /**
     * Asynchronously append data to the end of a file.
     *
     * This method appends the provided data to an existing file or creates
     * a new file if it doesn't exist. The data is added to the end of the
     * file without modifying existing content.
     *
     * @param string $path The path to the file to append data to
     * @param string $data The data to append to the file
     * @return CancellablePromiseInterface<int> Promise that resolves with number of bytes written
     * @throws \RuntimeException If the file cannot be opened for writing or append operation fails
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

        // Set up cancellation handler
        $promise->setCancelHandler(function () use ($operationId) {
            $this->eventLoop->cancelFileOperation($operationId);
        });

        return $promise;
    }

    /**
     * Asynchronously delete a file from the filesystem.
     *
     * This method removes the specified file from the filesystem. The operation
     * will fail if the file doesn't exist, is a directory, or cannot be deleted
     * due to permission issues.
     *
     * @param string $path The path to the file to delete
     * @return CancellablePromiseInterface<bool> Promise that resolves with true on successful deletion
     * @throws \RuntimeException If the file cannot be deleted or doesn't exist
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

        // Set up cancellation handler
        $promise->setCancelHandler(function () use ($operationId) {
            $this->eventLoop->cancelFileOperation($operationId);
        });

        return $promise;
    }

    /**
     * Asynchronously check if a file or directory exists.
     *
     * This method checks whether the specified path exists in the filesystem,
     * regardless of whether it's a file or directory. It performs a non-blocking
     * existence check without accessing the file contents.
     *
     * @param string $path The path to check for existence
     * @return CancellablePromiseInterface<bool> Promise that resolves with true if path exists, false otherwise
     * @throws \RuntimeException If the existence check fails due to system errors
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

        // Set up cancellation handler
        $promise->setCancelHandler(function () use ($operationId) {
            $this->eventLoop->cancelFileOperation($operationId);
        });

        return $promise;
    }

    /**
     * Asynchronously retrieve file statistics and metadata.
     *
     * This method returns detailed information about a file or directory including
     * size, permissions, timestamps, and file type. The information is equivalent
     * to what you would get from PHP's stat() function.
     *
     * @param string $path The path to get statistics for
     * @return CancellablePromiseInterface<array<string, mixed>> Promise that resolves with file stats array containing:
     *   - 'size' => int: File size in bytes
     *   - 'mtime' => int: Last modification time as Unix timestamp
     *   - 'atime' => int: Last access time as Unix timestamp
     *   - 'ctime' => int: Creation time as Unix timestamp
     *   - 'mode' => int: File permissions and type
     *   - 'is_file' => bool: Whether path is a regular file
     *   - 'is_dir' => bool: Whether path is a directory
     *   - 'is_readable' => bool: Whether file is readable
     *   - 'is_writable' => bool: Whether file is writable
     * @throws \RuntimeException If the file doesn't exist or stats cannot be retrieved
     */
    public function getFileStats(string $path): CancellablePromiseInterface
    {
        /** @var CancellablePromise<array<string, mixed>> $promise */
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
     * Asynchronously create a directory.
     *
     * This method creates a directory at the specified path. It can optionally
     * create parent directories recursively and set specific permissions.
     *
     * @param string $path The path where the directory should be created
     * @param array<string, mixed> $options Optional configuration options:
     *   - 'mode' => int: Directory permissions in octal format (default: 0755)
     *   - 'recursive' => bool: Create parent directories if they don't exist (default: false)
     * @return CancellablePromiseInterface<bool> Promise that resolves with true on successful creation
     * @throws \RuntimeException If the directory cannot be created or already exists
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

        // Set up cancellation handler
        $promise->setCancelHandler(function () use ($operationId) {
            $this->eventLoop->cancelFileOperation($operationId);
        });

        return $promise;
    }

    /**
     * Asynchronously remove an empty directory.
     *
     * This method removes a directory from the filesystem. The directory must
     * be empty before it can be removed. For recursive directory removal,
     * you'll need to delete all contents first.
     *
     * @param string $path The path to the directory to remove
     * @return CancellablePromiseInterface<bool> Promise that resolves with true on successful removal
     * @throws \RuntimeException If the directory doesn't exist, is not empty, or cannot be removed
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

        // Set up cancellation handler
        $promise->setCancelHandler(function () use ($operationId) {
            $this->eventLoop->cancelFileOperation($operationId);
        });

        return $promise;
    }

    /**
     * Asynchronously copy a file from source to destination.
     *
     * This method copies a file from the source path to the destination path.
     * If the destination file already exists, it will be overwritten. Parent
     * directories of the destination will be created if necessary.
     *
     * @param string $source The path to the source file to copy
     * @param string $destination The path where the file should be copied to
     * @return CancellablePromiseInterface<bool> Promise that resolves with true on successful copy
     * @throws \RuntimeException If the source file doesn't exist or copy operation fails
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

        // Set up cancellation handler
        $promise->setCancelHandler(function () use ($operationId) {
            $this->eventLoop->cancelFileOperation($operationId);
        });

        return $promise;
    }

    /**
     * Asynchronously rename or move a file.
     *
     * This method renames a file from the old path to the new path. This operation
     * can be used for both renaming files and moving them to different directories.
     * If the new path already exists, it will be overwritten.
     *
     * @param string $oldPath The current path of the file to rename/move
     * @param string $newPath The new path where the file should be renamed/moved to
     * @return CancellablePromiseInterface<bool> Promise that resolves with true on successful rename
     * @throws \RuntimeException If the source file doesn't exist or rename operation fails
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

        // Set up cancellation handler
        $promise->setCancelHandler(function () use ($operationId) {
            $this->eventLoop->cancelFileOperation($operationId);
        });

        return $promise;
    }

    /**
     * Start watching a file for changes.
     *
     * This method sets up a file system watcher that will monitor the specified
     * file for changes and call the provided callback when changes occur. The
     * watcher operates asynchronously and doesn't block execution.
     *
     * @param string $path The path to the file to watch for changes
     * @param callable $callback Callback function to execute when file changes:
     *   function(string $path, string $event, mixed $data): void
     *   - $path: The path that changed
     *   - $event: Type of change ('modified', 'deleted', 'created', etc.)
     *   - $data: Additional event data
     * @param array<string, mixed> $options Optional configuration options:
     *   - 'recursive' => bool: Watch subdirectories recursively (default: false)
     *   - 'events' => array: Specific events to watch for ['modify', 'delete', 'create']
     *   - 'debounce' => float: Minimum time between event notifications in seconds
     * @return string Unique watcher ID that can be used to stop watching
     * @throws \RuntimeException If the file watcher cannot be established
     */
    public function watchFile(string $path, callable $callback, array $options = []): string
    {
        return $this->eventLoop->addFileWatcher($path, $callback, $options);
    }

    /**
     * Stop watching a file for changes.
     *
     * This method removes a previously established file watcher using its
     * unique watcher ID. Once removed, the associated callback will no longer
     * be called for file changes.
     *
     * @param string $watcherId The unique watcher ID returned by watchFile()
     * @return bool True if the watcher was successfully removed, false if watcher ID not found
     */
    public function unwatchFile(string $watcherId): bool
    {
        return $this->eventLoop->removeFileWatcher($watcherId);
    }
}