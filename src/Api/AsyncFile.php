<?php

namespace Rcalicdan\FiberAsync\Api;

use Rcalicdan\FiberAsync\File\AsyncFileOperations;
use Rcalicdan\FiberAsync\Promise\Interfaces\CancellablePromiseInterface;

/**
 * Static API facade for asynchronous file and directory operations.
 *
 * This class provides a convenient static interface for performing asynchronous
 * file system operations without the need to instantiate objects. It acts as a
 * simplified facade over the AsyncFileOperations class, making it easy to perform
 * common file operations with a clean, static API.
 *
 * The API is designed for ease of use while maintaining all the power and flexibility
 * of the underlying async file system. All operations return cancellable promises
 * that integrate seamlessly with fiber-based event loops.
 *
 * Features:
 * - Static method interface for simplified usage
 * - Complete async file I/O operations (read, write, append, delete)
 * - Directory management (create, remove)
 * - File manipulation (copy, rename, move)
 * - File system monitoring (watch for changes)
 * - Streaming support for memory-efficient large file operations
 * - Singleton pattern with lazy initialization for performance
 * - Promise-based API with full cancellation support
 *
 * Usage Examples:
 * ```php
 * // Read a file
 * $content = await(AsyncFile::read('/path/to/file.txt'));
 * 
 * // Write data to a file
 * $bytesWritten = await(AsyncFile::write('/path/to/output.txt', 'Hello World'));
 * 
 * // Watch for file changes
 * $watcherId = AsyncFile::watch('/path/to/watch.txt', function($path, $event) {
 *     echo "File $path changed: $event\n";
 * });
 * ```
 *
 * @package Rcalicdan\FiberAsync\Api
 */
final class AsyncFile
{
    /**
     * @var AsyncFileOperations|null Cached singleton instance of the async operations handler
     */
    private static ?AsyncFileOperations $asyncOps = null;

    /**
     * Get the singleton instance of AsyncFileOperations with lazy initialization.
     *
     * This method implements the singleton pattern to ensure only one instance
     * of AsyncFileOperations exists throughout the application lifecycle, which
     * improves performance and maintains consistent state for file operations.
     *
     * @return AsyncFileOperations The shared async file operations handler instance
     */
    protected static function getAsyncFileOperations(): AsyncFileOperations
    {
        if (self::$asyncOps === null) {
            self::$asyncOps = new AsyncFileOperations;
        }

        return self::$asyncOps;
    }

    /**
     * Reset the cached AsyncFileOperations instance to its initial state.
     *
     * This method is primarily intended for testing purposes to ensure a clean
     * state between test runs. It clears the singleton instance, forcing a new
     * one to be created on the next method call.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$asyncOps = null;
    }

    /**
     * Asynchronously read the entire contents of a file.
     *
     * This method reads a file completely into memory and returns the contents
     * as a string. For large files that might consume significant memory,
     * consider using readFileStream() instead for better memory efficiency.
     *
     * @param string $path The path to the file to read
     * @param array<string, mixed> $options Optional configuration options:
     *   - 'encoding' => string: Character encoding (default: 'utf-8')
     *   - 'offset' => int: Starting position to read from (bytes)
     *   - 'length' => int: Maximum number of bytes to read
     *   - 'flags' => int: File operation flags for advanced control
     * @return CancellablePromiseInterface<string> Promise that resolves with the complete file contents as a string
     * @throws \RuntimeException If the file cannot be read, doesn't exist, or access is denied
     */
    public static function read(string $path, array $options = []): CancellablePromiseInterface
    {
        return self::getAsyncFileOperations()->readFile($path, $options);
    }

    /**
     * Asynchronously write data to a file.
     *
     * This method writes the provided data to a file, creating the file if it
     * doesn't exist or completely overwriting it if it does exist. For large
     * amounts of data or when memory efficiency is important, consider using
     * writeFileStream() instead.
     *
     * @param string $path The path where the file should be written
     * @param string $data The data to write to the file
     * @param array<string, mixed> $options Optional configuration options:
     *   - 'mode' => string: File write mode (default: 'w' for overwrite)
     *   - 'permissions' => int: File permissions in octal format (e.g., 0644)
     *   - 'create_dirs' => bool: Create parent directories if they don't exist
     *   - 'lock' => bool: Use file locking during write operation
     *   - 'atomic' => bool: Write to temporary file first, then rename (safer)
     * @return CancellablePromiseInterface<int> Promise that resolves with the number of bytes successfully written
     * @throws \RuntimeException If the file cannot be written, directory cannot be created, or insufficient permissions
     */
    public static function write(string $path, string $data, array $options = []): CancellablePromiseInterface
    {
        return self::getAsyncFileOperations()->writeFile($path, $data, $options);
    }

    /**
     * Asynchronously append data to the end of a file.
     *
     * This method adds the provided data to the end of an existing file without
     * modifying the existing content. If the file doesn't exist, it will be created.
     * This is particularly useful for logging, incremental data writing, or building
     * files progressively.
     *
     * @param string $path The path to the file to append data to
     * @param string $data The data to append to the end of the file
     * @return CancellablePromiseInterface<int> Promise that resolves with the number of bytes successfully appended
     * @throws \RuntimeException If the file cannot be opened for appending or the append operation fails
     */
    public static function append(string $path, string $data): CancellablePromiseInterface
    {
        return self::getAsyncFileOperations()->appendFile($path, $data);
    }

    /**
     * Asynchronously check if a file or directory exists.
     *
     * This method performs a non-blocking check to determine whether the specified
     * path exists in the filesystem. It works for both files and directories and
     * doesn't require read permissions on the target, making it safe for checking
     * existence without triggering access-related errors.
     *
     * @param string $path The filesystem path to check for existence
     * @return CancellablePromiseInterface<bool> Promise that resolves with true if the path exists, false otherwise
     * @throws \RuntimeException If the existence check fails due to system errors or invalid path format
     */
    public static function exists(string $path): CancellablePromiseInterface
    {
        return self::getAsyncFileOperations()->fileExists($path);
    }

    /**
     * Asynchronously retrieve detailed file statistics and metadata.
     *
     * This method returns comprehensive information about a file or directory,
     * including size, timestamps, permissions, and type information. The returned
     * data is similar to PHP's built-in stat() function but obtained asynchronously
     * without blocking the event loop.
     *
     * @param string $path The path to get statistics and metadata for
     * @return CancellablePromiseInterface<array<string, mixed>> Promise that resolves with detailed file information:
     *   - 'size' => int: File size in bytes
     *   - 'mtime' => int: Last modification time as Unix timestamp
     *   - 'atime' => int: Last access time as Unix timestamp
     *   - 'ctime' => int: Creation/change time as Unix timestamp
     *   - 'mode' => int: File permissions and type information
     *   - 'is_file' => bool: Whether the path is a regular file
     *   - 'is_dir' => bool: Whether the path is a directory
     *   - 'is_readable' => bool: Whether the current process can read the file
     *   - 'is_writable' => bool: Whether the current process can write to the file
     *   - 'is_executable' => bool: Whether the file is executable
     * @throws \RuntimeException If the file doesn't exist or statistics cannot be retrieved
     */
    public static function stats(string $path): CancellablePromiseInterface
    {
        return self::getAsyncFileOperations()->getFileStats($path);
    }

    /**
     * Asynchronously delete a file from the filesystem.
     *
     * This method permanently removes the specified file from the filesystem.
     * The operation will fail if the target is a directory, the file is currently
     * in use, or insufficient permissions exist. Use with caution as this operation
     * cannot be undone without backup systems in place.
     *
     * @param string $path The path to the file to permanently delete
     * @return CancellablePromiseInterface<bool> Promise that resolves with true on successful deletion
     * @throws \RuntimeException If the file doesn't exist, is a directory, is in use, or cannot be deleted
     */
    public static function delete(string $path): CancellablePromiseInterface
    {
        return self::getAsyncFileOperations()->deleteFile($path);
    }

    /**
     * Asynchronously copy a file from source to destination.
     *
     * This method creates a complete copy of a file at a new location. If the
     * destination file already exists, it will be overwritten. Parent directories
     * of the destination path will be created automatically if they don't exist.
     * The original file remains unchanged after the copy operation.
     *
     * @param string $source The path to the source file to copy
     * @param string $destination The path where the copy should be created
     * @return CancellablePromiseInterface<bool> Promise that resolves with true on successful file copy
     * @throws \RuntimeException If the source file doesn't exist, destination cannot be written, or copy operation fails
     */
    public static function copy(string $source, string $destination): CancellablePromiseInterface
    {
        return self::getAsyncFileOperations()->copyFile($source, $destination);
    }

    /**
     * Asynchronously rename or move a file.
     *
     * This method changes the name or location of a file by moving it from the
     * old path to the new path. This operation can rename files within the same
     * directory or move them to entirely different locations. If a file exists
     * at the new path, it will be overwritten without warning.
     *
     * @param string $oldPath The current path of the file to rename or move
     * @param string $newPath The new path where the file should be moved to
     * @return CancellablePromiseInterface<bool> Promise that resolves with true on successful rename/move operation
     * @throws \RuntimeException If the source file doesn't exist, destination directory doesn't exist, or insufficient permissions
     */
    public static function rename(string $oldPath, string $newPath): CancellablePromiseInterface
    {
        return self::getAsyncFileOperations()->renameFile($oldPath, $newPath);
    }

    /**
     * Start watching a file or directory for changes.
     *
     * This method establishes a filesystem watcher that monitors the specified
     * path for changes and executes a callback function when changes occur. The
     * watcher operates asynchronously and doesn't block execution. Multiple
     * watchers can be active simultaneously for different paths or even the same path.
     *
     * @param string $path The filesystem path to monitor for changes
     * @param callable $callback Function to execute when changes are detected:
     *   function(string $path, string $event, mixed $data): void
     *   - $path: The path where the change occurred
     *   - $event: Type of change ('modified', 'deleted', 'created', 'moved', 'attributes')
     *   - $data: Additional event-specific data (file size, timestamps, etc.)
     * @param array<string, mixed> $options Optional configuration options:
     *   - 'recursive' => bool: Monitor subdirectories recursively (default: false)
     *   - 'events' => array<string>: Specific events to watch (['modify', 'delete', 'create', 'move'])
     *   - 'debounce' => float: Minimum time between notifications for the same file (seconds)
     *   - 'include_patterns' => array<string>: File patterns to include (glob patterns)
     *   - 'exclude_patterns' => array<string>: File patterns to exclude (glob patterns)
     * @return string Unique watcher identifier that can be used to stop monitoring with unwatch()
     * @throws \RuntimeException If the path doesn't exist or file watcher cannot be established
     */
    public static function watch(string $path, callable $callback, array $options = []): string
    {
        return self::getAsyncFileOperations()->watchFile($path, $callback, $options);
    }

    /**
     * Stop watching a file or directory for changes.
     *
     * This method removes a previously established file system watcher using its
     * unique identifier. Once removed, the associated callback function will no
     * longer be executed when changes occur to the monitored path. This is important
     * for preventing memory leaks when watchers are no longer needed.
     *
     * @param string $watcherId The unique watcher identifier returned by watch()
     * @return bool True if the watcher was successfully removed, false if the watcher ID was not found
     */
    public static function unwatch(string $watcherId): bool
    {
        return self::getAsyncFileOperations()->unwatchFile($watcherId);
    }

    /**
     * Asynchronously create a directory.
     *
     * This method creates a new directory at the specified path with optional
     * configuration for permissions and recursive creation. If parent directories
     * don't exist, they can be automatically created when the recursive option
     * is enabled, making it easy to create deep directory structures in one operation.
     *
     * @param string $path The path where the directory should be created
     * @param array<string, mixed> $options Optional configuration options:
     *   - 'mode' => int: Directory permissions in octal format (default: 0755)
     *   - 'recursive' => bool: Create parent directories if they don't exist (default: false)
     *   - 'context' => resource: Stream context for advanced directory creation options
     * @return CancellablePromiseInterface<bool> Promise that resolves with true on successful directory creation
     * @throws \RuntimeException If the directory already exists, parent directories don't exist (when not recursive), or insufficient permissions
     */
    public static function createDirectory(string $path, array $options = []): CancellablePromiseInterface
    {
        return self::getAsyncFileOperations()->createDirectory($path, $options);
    }

    /**
     * Asynchronously remove an empty directory.
     *
     * This method removes a directory from the filesystem. The directory must be
     * completely empty (no files or subdirectories) before it can be removed.
     * For recursive directory removal, all contents must be deleted first using
     * other file and directory operations.
     *
     * @param string $path The path to the directory to remove
     * @return CancellablePromiseInterface<bool> Promise that resolves with true on successful directory removal
     * @throws \RuntimeException If the directory doesn't exist, is not empty, or cannot be removed due to permissions
     */
    public static function removeDirectory(string $path): CancellablePromiseInterface
    {
        return self::getAsyncFileOperations()->removeDirectory($path);
    }

    /**
     * Asynchronously open a file for streaming read operations.
     *
     * This method returns a file stream resource that can be used for reading
     * large files efficiently without loading the entire content into memory.
     * This is particularly useful for processing large files, log files, or
     * when memory usage needs to be controlled. Remember to properly close
     * the stream resource when finished to prevent resource leaks.
     *
     * @param string $path The path to the file to open for streaming reads
     * @param array<string, mixed> $options Optional configuration options:
     *   - 'mode' => string: File open mode (default: 'r' for read-only)
     *   - 'buffer_size' => int: Size of the read buffer in bytes (default: 8192)
     *   - 'context' => resource: Stream context for advanced stream options
     *   - 'use_include_path' => bool: Search in include_path for the file
     * @return CancellablePromiseInterface<resource> Promise that resolves with a file stream resource handle
     * @throws \RuntimeException If the file cannot be opened, doesn't exist, or access is denied
     */
    public static function readFileStream(string $path, array $options = []): CancellablePromiseInterface
    {
        return self::getAsyncFileOperations()->readFileStream($path, $options);
    }

    /**
     * Asynchronously write data to a file using streaming operations.
     *
     * This method writes data to a file using streaming I/O, which is more
     * memory efficient for large amounts of data. The streaming approach processes
     * data in chunks rather than loading everything into memory at once, making
     * it suitable for handling very large datasets. The target file will be
     * created if it doesn't exist or truncated if it does.
     *
     * @param string $path The path where the file should be written
     * @param string $data The data to write to the file using streaming
     * @param array<string, mixed> $options Optional configuration options:
     *   - 'mode' => string: File write mode (default: 'w' for write/truncate)
     *   - 'buffer_size' => int: Size of the write buffer in bytes (default: 8192)
     *   - 'create_dirs' => bool: Create parent directories if they don't exist
     *   - 'context' => resource: Stream context for advanced stream options
     *   - 'chunk_size' => int: Size of data chunks to process at once
     * @return CancellablePromiseInterface<int> Promise that resolves with the total number of bytes written
     * @throws \RuntimeException If the file cannot be written, directory cannot be created, or streaming fails
     */
    public static function writeFileStream(string $path, string $data, array $options = []): CancellablePromiseInterface
    {
        return self::getAsyncFileOperations()->writeFileStream($path, $data, $options);
    }

    /**
     * Asynchronously copy a file using streaming operations for memory efficiency.
     *
     * This method copies a file from source to destination using streaming I/O
     * to handle large files efficiently without consuming excessive memory. The
     * file is copied in chunks, making it suitable for very large files that
     * wouldn't fit comfortably in memory. Parent directories will be created
     * automatically if needed for the destination path.
     *
     * @param string $source The path to the source file to copy
     * @param string $destination The path where the file copy should be created
     * @return CancellablePromiseInterface<bool> Promise that resolves with true on successful streaming copy
     * @throws \RuntimeException If the source file doesn't exist, destination cannot be written, or streaming copy fails
     */
    public static function copyFileStream(string $source, string $destination): CancellablePromiseInterface
    {
        return self::getAsyncFileOperations()->copyFileStream($source, $destination);
    }
}