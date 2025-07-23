<?php

use Rcalicdan\FiberAsync\Api\AsyncFile;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

if (! function_exists('read_file_async')) {
    /**
     * Read a file asynchronously.
     *
     * Reads the contents of a file without blocking the event loop. The promise
     * resolves with the file contents as a string when the read operation completes.
     *
     * @param  string  $path  The file path to read
     * @param  array  $options  Optional parameters for the read operation
     * @return PromiseInterface Promise that resolves with file contents
     *
     * @example
     * $content = await(read_file_async('/path/to/file.txt'));
     */
    function read_file_async(string $path, array $options = []): PromiseInterface
    {
        return AsyncFile::read($path, $options);
    }
}

if (! function_exists('write_file_async')) {
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
     *
     * @example
     * $bytesWritten = await(write_file_async('/path/to/file.txt', 'Hello World'));
     */
    function write_file_async(string $path, string $data, array $options = []): PromiseInterface
    {
        return AsyncFile::write($path, $data, $options);
    }
}

if (! function_exists('append_file_async')) {
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
     *
     * @example
     * $bytesWritten = await(append_file_async('/path/to/log.txt', 'New log entry'));
     */
    function append_file_async(string $path, string $data): PromiseInterface
    {
        return AsyncFile::append($path, $data);
    }
}

if (! function_exists('file_exists_async')) {
    /**
     * Check if a file exists asynchronously.
     *
     * Checks for file existence without blocking the event loop. The promise
     * resolves with a boolean indicating whether the file exists.
     *
     * @param  string  $path  The file path to check
     * @return PromiseInterface Promise that resolves with boolean existence status
     *
     * @example
     * $exists = await(file_exists_async('/path/to/file.txt'));
     */
    function file_exists_async(string $path): PromiseInterface
    {
        return AsyncFile::exists($path);
    }
}

if (! function_exists('file_stats_async')) {
    /**
     * Get file information asynchronously.
     *
     * Retrieves file statistics and metadata without blocking the event loop.
     * The promise resolves with an array containing file information such as
     * size, modification time, permissions, etc.
     *
     * @param  string  $path  The file path to get information for
     * @return PromiseInterface Promise that resolves with file statistics
     *
     * @example
     * $stats = await(file_stats_async('/path/to/file.txt'));
     * echo "File size: " . $stats['size'];
     */
    function file_stats_async(string $path): PromiseInterface
    {
        return AsyncFile::stats($path);
    }
}

if (! function_exists('delete_file_async')) {
    /**
     * Delete a file asynchronously.
     *
     * Removes a file from the filesystem without blocking the event loop.
     * The promise resolves with a boolean indicating success or failure.
     *
     * @param  string  $path  The file path to delete
     * @return PromiseInterface Promise that resolves with deletion status
     *
     * @example
     * $deleted = await(delete_file_async('/path/to/file.txt'));
     */
    function delete_file_async(string $path): PromiseInterface
    {
        return AsyncFile::delete($path);
    }
}

if (! function_exists('copy_file_async')) {
    /**
     * Copy a file asynchronously.
     *
     * Copies a file from source to destination without blocking the event loop.
     * The promise resolves with a boolean indicating whether the copy was successful.
     *
     * @param  string  $source  The source file path
     * @param  string  $destination  The destination file path
     * @return PromiseInterface Promise that resolves with copy status
     *
     * @example
     * $copied = await(copy_file_async('/src/file.txt', '/dest/file.txt'));
     */
    function copy_file_async(string $source, string $destination): PromiseInterface
    {
        return AsyncFile::copy($source, $destination);
    }
}

if (! function_exists('rename_file_async')) {
    /**
     * Rename a file asynchronously.
     *
     * Renames or moves a file from old path to new path without blocking the
     * event loop. The promise resolves with a boolean indicating success.
     *
     * @param  string  $oldPath  The current file path
     * @param  string  $newPath  The new file path
     * @return PromiseInterface Promise that resolves with rename status
     *
     * @example
     * $renamed = await(rename_file_async('/old/path.txt', '/new/path.txt'));
     */
    function rename_file_async(string $oldPath, string $newPath): PromiseInterface
    {
        return AsyncFile::rename($oldPath, $newPath);
    }
}

if (! function_exists('watch_file_async')) {
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
     *
     * @example
     * $watcherId = watch_file_async('/path/to/file.txt', function($event) {
     *     echo "File changed: " . $event['type'];
     * });
     */
    function watch_file_async(string $path, callable $callback, array $options = []): string
    {
        return AsyncFile::watch($path, $callback, $options);
    }
}

if (! function_exists('unwatch_file_async')) {
    /**
     * Stop watching a file for changes.
     *
     * Removes a file watcher using the watcher ID returned by watch_file().
     * Returns a boolean indicating whether the watcher was successfully removed.
     *
     * @param  string  $watcherId  The watcher ID to remove
     * @return bool True if watcher was removed, false otherwise
     *
     * @example
     * $success = unwatch_file_async($watcherId);
     */
    function unwatch_file_async(string $watcherId): bool
    {
        return AsyncFile::unwatch($watcherId);
    }
}

if (! function_exists('create_directory_async')) {
    /**
     * Create a directory asynchronously.
     *
     * Creates a directory (and parent directories if needed) without blocking
     * the event loop. The promise resolves with a boolean indicating success.
     *
     * @param  string  $path  The directory path to create
     * @param  array  $options  Creation options (recursive, permissions, etc.)
     * @return PromiseInterface Promise that resolves with creation status
     *
     * @example
     * $created = await(create_directory_async('/path/to/new/dir', ['recursive' => true]));
     */
    function create_directory_async(string $path, array $options = []): PromiseInterface
    {
        return AsyncFile::createDirectory($path, $options);
    }
}

if (! function_exists('remove_directory_async')) {
    /**
     * Remove a directory asynchronously.
     *
     * Removes a directory from the filesystem without blocking the event loop.
     * The promise resolves with a boolean indicating success or failure.
     *
     * @param  string  $path  The directory path to remove
     * @return PromiseInterface Promise that resolves with removal status
     *
     * @example
     * $removed = await(remove_directory_async('/path/to/dir'));
     */
    function remove_directory_async(string $path): PromiseInterface
    {
        return AsyncFile::removeDirectory($path);
    }
}

if (! function_exists('mkdir_recursive_async')) {
    /**
     * Create a directory recursively asynchronously.
     *
     * Creates a directory and all necessary parent directories without blocking
     * the event loop. This is a convenience function for recursive directory creation.
     *
     * @param  string  $path  The directory path to create
     * @param  int  $permissions  Directory permissions (default: 0755)
     * @return PromiseInterface Promise that resolves with creation status
     *
     * @example
     * $created = await(mkdir_recursive_async('/deep/nested/path', 0755));
     */
    function mkdir_recursive_async(string $path, int $permissions = 0755): PromiseInterface
    {
        return AsyncFile::createDirectory($path, ['recursive' => true, 'mode' => $permissions]);
    }
}

if (! function_exists('get_file_size_async')) {
    /**
     * Get the size of a file asynchronously.
     *
     * Retrieves just the file size without blocking the event loop.
     * This is a convenience function that extracts size from file stats.
     *
     * @param  string  $path  The file path to get size for
     * @return PromiseInterface Promise that resolves with file size in bytes
     *
     * @example
     * $size = await(get_file_size_async('/path/to/file.txt'));
     */
    function get_file_size_async(string $path): PromiseInterface
    {
        return AsyncFile::stats($path)->then(function ($stats) {
            return $stats['size'] ?? 0;
        });
    }
}

if (! function_exists('get_file_mtime_async')) {
    /**
     * Get the modification time of a file asynchronously.
     *
     * Retrieves the last modification time without blocking the event loop.
     * This is a convenience function that extracts mtime from file stats.
     *
     * @param  string  $path  The file path to get modification time for
     * @return PromiseInterface Promise that resolves with Unix timestamp
     *
     * @example
     * $mtime = await(get_file_mtime_async('/path/to/file.txt'));
     */
    function get_file_mtime_async(string $path): PromiseInterface
    {
        return AsyncFile::stats($path)->then(function ($stats) {
            return $stats['mtime'] ?? 0;
        });
    }
}

if (! function_exists('read_file_stream_async')) {
    /**
     * Read a file as a stream asynchronously.
     *
     * Reads a file in chunks as a stream without loading the entire file into memory.
     * Useful for handling large files efficiently.
     *
     * @param  string  $path  The file path to read
     * @param  array  $options  Stream options (chunk size, etc.)
     * @return PromiseInterface Promise that resolves with file stream
     *
     * @example
     * $stream = await(read_file_stream_async('/path/to/large-file.txt'));
     */
    function read_file_stream_async(string $path, array $options = []): PromiseInterface
    {
        return AsyncFile::readFileStream($path, $options);
    }
}

if (! function_exists('write_file_stream_async')) {
    /**
     * Write to a file as a stream asynchronously.
     *
     * Writes data to a file in chunks as a stream without loading all data into memory.
     * Useful for handling large amounts of data efficiently.
     *
     * @param  string  $path  The file path to write to
     * @param  string  $data  The data to write
     * @param  array  $options  Stream options (chunk size, etc.)
     * @return PromiseInterface Promise that resolves with bytes written
     *
     * @example
     * $bytesWritten = await(write_file_stream_async('/path/to/file.txt', $largeData));
     */
    function write_file_stream_async(string $path, string $data, array $options = []): PromiseInterface
    {
        return AsyncFile::writeFileStream($path, $data, $options);
    }
}

if (! function_exists('copy_file_stream_async')) {
    /**
     * Copy a file using streaming asynchronously.
     *
     * Copies a file from source to destination using streaming to handle large files
     * efficiently without loading the entire file into memory.
     *
     * @param  string  $source  The source file path
     * @param  string  $destination  The destination file path
     * @return PromiseInterface Promise that resolves with copy status
     *
     * @example
     * $copied = await(copy_file_stream_async('/src/large-file.txt', '/dest/large-file.txt'));
     */
    function copy_file_stream_async(string $source, string $destination): PromiseInterface
    {
        return AsyncFile::copyFileStream($source, $destination);
    }
}
