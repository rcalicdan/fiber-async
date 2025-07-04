<?php

use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Facades\AsyncFile;

/**
 * Read a file asynchronously.
 *
 * Reads the contents of a file without blocking the event loop. The promise
 * resolves with the file contents as a string when the read operation completes.
 *
 * @param  string  $path  The file path to read
 * @param  array  $options  Optional parameters for the read operation
 * @return PromiseInterface Promise that resolves with file contents
 */
function read_file_async(string $path, array $options = []): PromiseInterface
{
    return AsyncFile::read($path, $options);
}

/**
 * Write to a file asynchronously.
 *
 * Writes data to a file without blocking the event loop. The promise resolves
 * with the number of bytes written when the operation completes.
 *
 * @param  string  $path  The file path to write to
 * @param  string  $data  The data to write
 * @param  array  $options  Write options (append, permissions, etc.)
 * @return PromiseInterface Promise that resolves with bytes written
 */
function write_file_async(string $path, string $data, array $options = []): PromiseInterface
{
    return AsyncFile::write($path, $data, $options);
}

/**
 * Append to a file asynchronously.
 *
 * Appends data to the end of an existing file without blocking the event loop.
 * If the file doesn't exist, it will be created. The promise resolves with
 * the number of bytes written.
 *
 * @param  string  $path  The file path to append to
 * @param  string  $data  The data to append
 * @return PromiseInterface Promise that resolves with bytes written
 */
function append_file_async(string $path, string $data): PromiseInterface
{
    return AsyncFile::append($path, $data);
}

/**
 * Check if a file exists asynchronously.
 *
 * Checks for file existence without blocking the event loop. The promise
 * resolves with a boolean indicating whether the file exists.
 *
 * @param  string  $path  The file path to check
 * @return PromiseInterface Promise that resolves with boolean existence status
 */
function file_exists_async(string $path): PromiseInterface
{
    return AsyncFile::exists($path);
}

/**
 * Get file information asynchronously.
 *
 * Retrieves file statistics and metadata without blocking the event loop.
 * The promise resolves with an array containing file information such as
 * size, modification time, permissions, etc.
 *
 * @param  string  $path  The file path to get information for
 * @return PromiseInterface Promise that resolves with file statistics
 */
function file_stats_async(string $path): PromiseInterface
{
    return AsyncFile::stats($path);
}

/**
 * Delete a file asynchronously.
 *
 * Removes a file from the filesystem without blocking the event loop.
 * The promise resolves with a boolean indicating success or failure.
 *
 * @param  string  $path  The file path to delete
 * @return PromiseInterface Promise that resolves with deletion status
 */
function delete_file_async(string $path): PromiseInterface
{
    return AsyncFile::delete($path);
}

/**
 * Copy a file asynchronously.
 *
 * Copies a file from source to destination without blocking the event loop.
 * The promise resolves with a boolean indicating whether the copy was successful.
 *
 * @param  string  $source  The source file path
 * @param  string  $destination  The destination file path
 * @return PromiseInterface Promise that resolves with copy status
 */
function copy_file_async(string $source, string $destination): PromiseInterface
{
    return AsyncFile::copy($source, $destination);
}

/**
 * Rename a file asynchronously.
 *
 * Renames or moves a file from old path to new path without blocking the
 * event loop. The promise resolves with a boolean indicating success.
 *
 * @param  string  $oldPath  The current file path
 * @param  string  $newPath  The new file path
 * @return PromiseInterface Promise that resolves with rename status
 */
function rename_file_async(string $oldPath, string $newPath): PromiseInterface
{
    return AsyncFile::rename($oldPath, $newPath);
}

/**
 * Watch a file for changes asynchronously.
 *
 * Sets up a file watcher that monitors a file for changes and calls the
 * provided callback when changes occur. Returns a watcher ID that can be
 * used to stop watching later.
 *
 * @param  string  $path  The file path to watch
 * @param  callable  $callback  Callback to execute when file changes
 * @param  array  $options  Watch options (polling interval, etc.)
 * @return string Watcher ID for managing the watch operation
 */
function watch_file_async(string $path, callable $callback, array $options = []): string
{
    return AsyncFile::watch($path, $callback, $options);
}

/**
 * Stop watching a file for changes.
 *
 * Removes a file watcher using the watcher ID returned by watch_file().
 * Returns a boolean indicating whether the watcher was successfully removed.
 *
 * @param  string  $watcherId  The watcher ID to remove
 * @return bool True if watcher was removed, false otherwise
 */
function unwatch_file_async(string $watcherId): bool
{
    return AsyncFile::unwatch($watcherId);
}

/**
 * Create a directory asynchronously.
 *
 * Creates a directory (and parent directories if needed) without blocking
 * the event loop. The promise resolves with a boolean indicating success.
 *
 * @param  string  $path  The directory path to create
 * @param  array  $options  Creation options (recursive, permissions, etc.)
 * @return PromiseInterface Promise that resolves with creation status
 */
function create_directory_async(string $path, array $options = []): PromiseInterface
{
    return AsyncFile::createDirectory($path, $options);
}

/**
 * Remove a directory asynchronously.
 *
 * Removes a directory from the filesystem without blocking the event loop.
 * The promise resolves with a boolean indicating success or failure.
 *
 * @param  string  $path  The directory path to remove
 * @return PromiseInterface Promise that resolves with removal status
 */
function remove_directory_async(string $path): PromiseInterface
{
    return AsyncFile::removeDirectory($path);
}

/**
 * Create a directory recursively asynchronously.
 *
 * Creates a directory and all necessary parent directories without blocking
 * the event loop. This is a convenience function for recursive directory creation.
 *
 * @param  string  $path  The directory path to create
 * @param  int  $permissions  Directory permissions (default: 0755)
 * @return PromiseInterface Promise that resolves with creation status
 */
function mkdir_recursive_async(string $path, int $permissions = 0755): PromiseInterface
{
    return AsyncFile::createDirectory($path, ['recursive' => true, 'mode' => $permissions]);
}

/**
 * Get the size of a file asynchronously.
 *
 * Retrieves just the file size without blocking the event loop.
 * This is a convenience function that extracts size from file stats.
 *
 * @param  string  $path  The file path to get size for
 * @return PromiseInterface Promise that resolves with file size in bytes
 */
function get_file_size_async(string $path): PromiseInterface
{
    return AsyncFile::stats($path)->then(function ($stats) {
        return $stats['size'] ?? 0;
    });
}

/**
 * Get the modification time of a file asynchronously.
 *
 * Retrieves the last modification time without blocking the event loop.
 * This is a convenience function that extracts mtime from file stats.
 *
 * @param  string  $path  The file path to get modification time for
 * @return PromiseInterface Promise that resolves with Unix timestamp
 */
function get_file_mtime_async(string $path): PromiseInterface
{
    return AsyncFile::stats($path)->then(function ($stats) {
        return $stats['mtime'] ?? 0;
    });
}
