<?php

namespace Rcalicdan\FiberAsync\Api;

use Rcalicdan\FiberAsync\AsyncFileOperations;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

/**
 * Static facade for asynchronous file and directory operations.
 *
 * This facade provides a comprehensive set of file system operations that
 * execute asynchronously without blocking the event loop. It includes file
 * reading, writing, directory management, and file watching capabilities.
 */
final class AsyncFile
{
    /**
     * @var AsyncFileOperations|null Cached instance of core async operations handler
     */
    private static ?AsyncFileOperations $asyncOps = null;

    /**
     * Get the singleton instance of AsyncFileOperations with lazy initialization.
     *
     * @return AsyncFileOperations The core async operations handler
     */
    protected static function getAsyncFileOperations(): AsyncFileOperations
    {
        if (self::$asyncOps === null) {
            self::$asyncOps = new AsyncFileOperations;
        }

        return self::$asyncOps;
    }

    /**
     * Reset cached instance to initial state.
     */
    public static function reset(): void
    {
        self::$asyncOps = null;
    }

    /**
     * Read a file asynchronously.
     *
     * @param  string  $path  The file path to read
     * @param  array  $options  Optional parameters for the read operation
     * @return PromiseInterface Promise that resolves with file contents
     */
    public static function read(string $path, array $options = []): PromiseInterface
    {
        return self::getAsyncFileOperations()->readFile($path, $options);
    }

    /**
     * Write to a file asynchronously.
     *
     * @param  string  $path  The file path to write to
     * @param  string  $data  The data to write
     * @param  array  $options  Write options (append, permissions, etc.)
     * @return PromiseInterface Promise that resolves with bytes written
     */
    public static function write(string $path, string $data, array $options = []): PromiseInterface
    {
        return self::getAsyncFileOperations()->writeFile($path, $data, $options);
    }

    /**
     * Append to a file asynchronously.
     *
     * @param  string  $path  The file path to append to
     * @param  string  $data  The data to append
     * @return PromiseInterface Promise that resolves with bytes written
     */
    public static function append(string $path, string $data): PromiseInterface
    {
        return self::getAsyncFileOperations()->appendFile($path, $data);
    }

    /**
     * Check if a file exists asynchronously.
     *
     * @param  string  $path  The file path to check
     * @return PromiseInterface Promise that resolves with boolean existence status
     */
    public static function exists(string $path): PromiseInterface
    {
        return self::getAsyncFileOperations()->fileExists($path);
    }

    /**
     * Get file information asynchronously.
     *
     * @param  string  $path  The file path to get information for
     * @return PromiseInterface Promise that resolves with file statistics
     */
    public static function stats(string $path): PromiseInterface
    {
        return self::getAsyncFileOperations()->getFileStats($path);
    }

    /**
     * Delete a file asynchronously.
     *
     * @param  string  $path  The file path to delete
     * @return PromiseInterface Promise that resolves with deletion status
     */
    public static function delete(string $path): PromiseInterface
    {
        return self::getAsyncFileOperations()->deleteFile($path);
    }

    /**
     * Copy a file asynchronously.
     *
     * @param  string  $source  The source file path
     * @param  string  $destination  The destination file path
     * @return PromiseInterface Promise that resolves with copy status
     */
    public static function copy(string $source, string $destination): PromiseInterface
    {
        return self::getAsyncFileOperations()->copyFile($source, $destination);
    }

    /**
     * Rename a file asynchronously.
     *
     * @param  string  $oldPath  The current file path
     * @param  string  $newPath  The new file path
     * @return PromiseInterface Promise that resolves with rename status
     */
    public static function rename(string $oldPath, string $newPath): PromiseInterface
    {
        return self::getAsyncFileOperations()->renameFile($oldPath, $newPath);
    }

    /**
     * Watch a file for changes asynchronously.
     *
     * @param  string  $path  The file path to watch
     * @param  callable  $callback  Callback to execute when file changes
     * @param  array  $options  Watch options (polling interval, etc.)
     * @return string Watcher ID for managing the watch operation
     */
    public static function watch(string $path, callable $callback, array $options = []): string
    {
        return self::getAsyncFileOperations()->watchFile($path, $callback, $options);
    }

    /**
     * Stop watching a file for changes.
     *
     * @param  string  $watcherId  The watcher ID to remove
     * @return bool True if watcher was removed, false otherwise
     */
    public static function unwatch(string $watcherId): bool
    {
        return self::getAsyncFileOperations()->unwatchFile($watcherId);
    }

    /**
     * Create a directory asynchronously.
     *
     * @param  string  $path  The directory path to create
     * @param  array  $options  Creation options (recursive, permissions, etc.)
     * @return PromiseInterface Promise that resolves with creation status
     */
    public static function createDirectory(string $path, array $options = []): PromiseInterface
    {
        return self::getAsyncFileOperations()->createDirectory($path, $options);
    }

    /**
     * Remove a directory asynchronously.
     *
     * @param  string  $path  The directory path to remove
     * @return PromiseInterface Promise that resolves with removal status
     */
    public static function removeDirectory(string $path): PromiseInterface
    {
        return self::getAsyncFileOperations()->removeDirectory($path);
    }

    public static function readFileStream(string $path, array $options = []): PromiseInterface
    {
        return self::getAsyncFileOperations()->readFileStream($path, $options);
    }

    public static function writeFileStream(string $path, string $data, array $options = []): PromiseInterface
    {
        return self::getAsyncFileOperations()->writeFileStream($path, $data, $options);
    }

    public static function copyFileStream(string $source, string $destination): PromiseInterface
    {
        return self::getAsyncFileOperations()->copyFileStream($source, $destination);
    }
}
