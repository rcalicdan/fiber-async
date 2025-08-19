<?php

use Rcalicdan\FiberAsync\Api\File;
use Rcalicdan\FiberAsync\Promise\Interfaces\CancellablePromiseInterface;

if (! function_exists('read_file_async')) {
    /**
     * Asynchronously read the entire contents of a file.
     *
     * This function reads a file completely into memory and returns the contents
     * as a string. For large files that might consume significant memory,
     * consider using read_file_stream_async() instead for better memory efficiency.
     * This is a convenience wrapper around File::read().
     *
     * @param  string  $path  The path to the file to read
     * @param  array<string, mixed>  $options  Optional configuration options:
     *                                         - 'encoding' => string: Character encoding (default: 'utf-8')
     *                                         - 'offset' => int: Starting position to read from (bytes)
     *                                         - 'length' => int: Maximum number of bytes to read
     *                                         - 'flags' => int: File operation flags for advanced control
     * @return CancellablePromiseInterface<string> Promise that resolves with the complete file contents as a string
     *
     * @throws RuntimeException If the file cannot be read, doesn't exist, or access is denied
     *                          - example
     *                          ```php
     *                          // Read a text file
     *                          $content = await(read_file_async('/path/to/file.txt'));
     *
     * // Read with specific encoding
     * $content = await(read_file_async('/path/to/file.txt', ['encoding' => 'utf-8']));
     *
     * // Read a portion of the file
     * $partial = await(read_file_async('/path/to/file.txt', ['offset' => 10, 'length' => 100]));
     * ```
     */
    function read_file_async(string $path, array $options = []): CancellablePromiseInterface
    {
        return File::read($path, $options);
    }
}

if (! function_exists('write_file_async')) {
    /**
     * Asynchronously write data to a file.
     *
     * This function writes the provided data to a file, creating the file if it
     * doesn't exist or completely overwriting it if it does exist. For large
     * amounts of data or when memory efficiency is important, consider using
     * write_file_stream_async() instead. This is a convenience wrapper around File::write().
     *
     * @param  string  $path  The path where the file should be written
     * @param  string  $data  The data to write to the file
     * @param  array<string, mixed>  $options  Optional configuration options:
     *                                         - 'mode' => string: File write mode (default: 'w' for overwrite)
     *                                         - 'permissions' => int: File permissions in octal format (e.g., 0644)
     *                                         - 'create_dirs' => bool: Create parent directories if they don't exist
     *                                         - 'lock' => bool: Use file locking during write operation
     *                                         - 'atomic' => bool: Write to temporary file first, then rename (safer)
     * @return CancellablePromiseInterface<int> Promise that resolves with the number of bytes successfully written
     *
     * @throws RuntimeException If the file cannot be written, directory cannot be created, or insufficient permissions
     *
     * ```php
     * // Write text to a file
     * $bytesWritten = await(write_file_async('/path/to/file.txt', 'Hello World'));
     *
     * // Write with custom permissions
     * $bytesWritten = await(write_file_async('/path/to/file.txt', 'Hello', ['permissions' => 0644]));
     *
     * // Atomic write operation
     * $bytesWritten = await(write_file_async('/path/to/file.txt', 'Data', ['atomic' => true]));
     * ```
     */
    function write_file_async(string $path, string $data, array $options = []): CancellablePromiseInterface
    {
        return File::write($path, $data, $options);
    }
}

if (! function_exists('append_file_async')) {
    /**
     * Asynchronously append data to the end of a file.
     *
     * This function adds the provided data to the end of an existing file without
     * modifying the existing content. If the file doesn't exist, it will be created.
     * This is particularly useful for logging, incremental data writing, or building
     * files progressively. This is a convenience wrapper around File::append().
     *
     * @param  string  $path  The path to the file to append data to
     * @param  string  $data  The data to append to the end of the file
     * @return CancellablePromiseInterface<int> Promise that resolves with the number of bytes successfully appended
     *
     * @throws RuntimeException If the file cannot be opened for appending or the append operation fails
     *
     * ```php
     * // Append to a log file
     * $bytesWritten = await(append_file_async('/path/to/log.txt', 'New log entry\n'));
     *
     * // Append timestamp with data
     * $timestamp = date('Y-m-d H:i:s');
     * $bytesWritten = await(append_file_async('/path/to/log.txt', "[$timestamp] Event occurred\n"));
     * ```
     */
    function append_file_async(string $path, string $data): CancellablePromiseInterface
    {
        return File::append($path, $data);
    }
}

if (! function_exists('file_exists_async')) {
    /**
     * Asynchronously check if a file or directory exists.
     *
     * This function performs a non-blocking check to determine whether the specified
     * path exists in the filesystem. It works for both files and directories and
     * doesn't require read permissions on the target, making it safe for checking
     * existence without triggering access-related errors. This is a convenience wrapper around File::exists().
     *
     * @param  string  $path  The filesystem path to check for existence
     * @return CancellablePromiseInterface<bool> Promise that resolves with true if the path exists, false otherwise
     *
     * @throws RuntimeException If the existence check fails due to system errors or invalid path format
     *                          - example
     *                          ```php
     *                          // Check if a file exists
     *                          $exists = await(file_exists_async('/path/to/file.txt'));
     *                          if ($exists) {
     *                          echo "File exists!";
     *                          }
     *
     * // Check directory existence
     * $dirExists = await(file_exists_async('/path/to/directory'));
     * ```
     */
    function file_exists_async(string $path): CancellablePromiseInterface
    {
        return File::exists($path);
    }
}

if (! function_exists('file_stats_async')) {
    /**
     * Asynchronously retrieve detailed file statistics and metadata.
     *
     * This function returns comprehensive information about a file or directory,
     * including size, timestamps, permissions, and type information. The returned
     * data is similar to PHP's built-in stat() function but obtained asynchronously
     * without blocking the event loop. This is a convenience wrapper around File::stats().
     *
     * @param  string  $path  The path to get statistics and metadata for
     * @return CancellablePromiseInterface<array<string, mixed>> Promise that resolves with detailed file information:
     *                                                           - 'size' => int: File size in bytes
     *                                                           - 'mtime' => int: Last modification time as Unix timestamp
     *                                                           - 'atime' => int: Last access time as Unix timestamp
     *                                                           - 'ctime' => int: Creation/change time as Unix timestamp
     *                                                           - 'mode' => int: File permissions and type information
     *                                                           - 'is_file' => bool: Whether the path is a regular file
     *                                                           - 'is_dir' => bool: Whether the path is a directory
     *                                                           - 'is_readable' => bool: Whether the current process can read the file
     *                                                           - 'is_writable' => bool: Whether the current process can write to the file
     *                                                           - 'is_executable' => bool: Whether the file is executable
     *
     * @throws RuntimeException If the file doesn't exist or statistics cannot be retrieved
     *                          - example
     *                          ```php
     *                          // Get file statistics
     *                          $stats = await(file_stats_async('/path/to/file.txt'));
     *                          echo "File size: " . $stats['size'] . " bytes\n";
     *                          echo "Last modified: " . date('Y-m-d H:i:s', $stats['mtime']) . "\n";
     *                          echo "Is readable: " . ($stats['is_readable'] ? 'Yes' : 'No') . "\n";
     *                          ```
     */
    function file_stats_async(string $path): CancellablePromiseInterface
    {
        return File::stats($path);
    }
}

if (! function_exists('delete_file_async')) {
    /**
     * Asynchronously delete a file from the filesystem.
     *
     * This function permanently removes the specified file from the filesystem.
     * The operation will fail if the target is a directory, the file is currently
     * in use, or insufficient permissions exist. Use with caution as this operation
     * cannot be undone without backup systems in place. This is a convenience wrapper around File::delete().
     *
     * @param  string  $path  The path to the file to permanently delete
     * @return CancellablePromiseInterface<bool> Promise that resolves with true on successful deletion
     *
     * @throws RuntimeException If the file doesn't exist, is a directory, is in use, or cannot be deleted
     *                          - example
     *                          ```php
     *                          // Delete a temporary file
     *                          $deleted = await(delete_file_async('/tmp/temporary_file.txt'));
     *                          if ($deleted) {
     *                          echo "File successfully deleted";
     *                          }
     *
     * // Delete with error handling
     * try {
     *     $deleted = await(delete_file_async('/path/to/file.txt'));
     * } catch (\RuntimeException $e) {
     *     echo "Failed to delete file: " . $e->getMessage();
     * }
     * ```
     */
    function delete_file_async(string $path): CancellablePromiseInterface
    {
        return File::delete($path);
    }
}

if (! function_exists('copy_file_async')) {
    /**
     * Asynchronously copy a file from source to destination.
     *
     * This function creates a complete copy of a file at a new location. If the
     * destination file already exists, it will be overwritten. Parent directories
     * of the destination path will be created automatically if they don't exist.
     * The original file remains unchanged after the copy operation. This is a convenience wrapper around File::copy().
     *
     * @param  string  $source  The path to the source file to copy
     * @param  string  $destination  The path where the copy should be created
     * @return CancellablePromiseInterface<bool> Promise that resolves with true on successful file copy
     *
     * @throws RuntimeException If the source file doesn't exist, destination cannot be written, or copy operation fails
     *                          - example
     *                          ```php
     *                          // Copy a configuration file
     *                          $copied = await(copy_file_async('/etc/config.conf', '/backup/config.conf'));
     *                          if ($copied) {
     *                          echo "Configuration backup created successfully";
     *                          }
     *
     * // Copy to different directory
     * $copied = await(copy_file_async('/src/document.pdf', '/dest/documents/document.pdf'));
     * ```
     */
    function copy_file_async(string $source, string $destination): CancellablePromiseInterface
    {
        return File::copy($source, $destination);
    }
}

if (! function_exists('rename_file_async')) {
    /**
     * Asynchronously rename or move a file.
     *
     * This function changes the name or location of a file by moving it from the
     * old path to the new path. This operation can rename files within the same
     * directory or move them to entirely different locations. If a file exists
     * at the new path, it will be overwritten without warning. This is a convenience wrapper around File::rename().
     *
     * @param  string  $oldPath  The current path of the file to rename or move
     * @param  string  $newPath  The new path where the file should be moved to
     * @return CancellablePromiseInterface<bool> Promise that resolves with true on successful rename/move operation
     *
     * @throws RuntimeException If the source file doesn't exist, destination directory doesn't exist, or insufficient permissions
     *                          - example
     *                          ```php
     *                          // Rename a file in the same directory
     *                          $renamed = await(rename_file_async('/path/old_name.txt', '/path/new_name.txt'));
     *
     * // Move file to different directory
     * $moved = await(rename_file_async('/temp/file.txt', '/permanent/file.txt'));
     *
     * // Rename with timestamp
     * $timestamp = date('Y-m-d_H-i-s');
     * $renamed = await(rename_file_async('/logs/app.log', "/logs/app_{$timestamp}.log"));
     * ```
     */
    function rename_file_async(string $oldPath, string $newPath): CancellablePromiseInterface
    {
        return File::rename($oldPath, $newPath);
    }
}

if (! function_exists('watch_file_async')) {
    /**
     * Start watching a file or directory for changes.
     *
     * This function establishes a filesystem watcher that monitors the specified
     * path for changes and executes a callback function when changes occur. The
     * watcher operates asynchronously and doesn't block execution. Multiple
     * watchers can be active simultaneously for different paths or even the same path.
     * This is a convenience wrapper around File::watch().
     *
     * @param  string  $path  The filesystem path to monitor for changes
     * @param  callable  $callback  Function to execute when changes are detected:
     *                              function(string $path, string $event, mixed $data): void
     *                              - $path: The path where the change occurred
     *                              - $event: Type of change ('modified', 'deleted', 'created', 'moved', 'attributes')
     *                              - $data: Additional event-specific data (file size, timestamps, etc.)
     * @param  array<string, mixed>  $options  Optional configuration options:
     *                                         - 'recursive' => bool: Monitor subdirectories recursively (default: false)
     *                                         - 'events' => array<string>: Specific events to watch (['modify', 'delete', 'create', 'move'])
     *                                         - 'debounce' => float: Minimum time between notifications for the same file (seconds)
     *                                         - 'include_patterns' => array<string>: File patterns to include (glob patterns)
     *                                         - 'exclude_patterns' => array<string>: File patterns to exclude (glob patterns)
     * @return string Unique watcher identifier that can be used to stop monitoring with unwatch_file_async()
     *
     * @throws RuntimeException If the path doesn't exist or file watcher cannot be established
     *                          - example
     *                          ```php
     *                          // Watch a single file for changes
     *                          $watcherId = watch_file_async('/path/to/config.txt', function($path, $event, $data) {
     *                          echo "File $path changed: $event\n";
     *                          if ($event === 'modified') {
     *                          echo "New size: {$data['size']} bytes\n";
     *                          }
     *                          });
     *
     * // Watch directory recursively with filtering
     * $watcherId = watch_file_async('/src', function($path, $event) {
     *     echo "Source file $path was $event\n";
     * }, [
     *     'recursive' => true,
     *     'include_patterns' => ['*.php', '*.js'],
     *     'events' => ['modified', 'created', 'deleted']
     * ]);
     * ```
     */
    function watch_file_async(string $path, callable $callback, array $options = []): string
    {
        return File::watch($path, $callback, $options);
    }
}

if (! function_exists('unwatch_file_async')) {
    /**
     * Stop watching a file or directory for changes.
     *
     * This function removes a previously established file system watcher using its
     * unique identifier. Once removed, the associated callback function will no
     * longer be executed when changes occur to the monitored path. This is important
     * for preventing memory leaks when watchers are no longer needed.
     * This is a convenience wrapper around File::unwatch().
     *
     * @param  string  $watcherId  The unique watcher identifier returned by watch_file_async()
     * @return bool True if the watcher was successfully removed, false if the watcher ID was not found
     *              - example
     *              ```php
     *              // Start watching a file
     *              $watcherId = watch_file_async('/path/to/file.txt', function($path, $event) {
     *              echo "File changed: $event\n";
     *              });
     *
     * // Stop watching after some condition
     * $success = unwatch_file_async($watcherId);
     * if ($success) {
     *     echo "File watcher stopped successfully";
     * } else {
     *     echo "Watcher ID not found or already stopped";
     * }
     * ```
     */
    function unwatch_file_async(string $watcherId): bool
    {
        return File::unwatch($watcherId);
    }
}

if (! function_exists('create_directory_async')) {
    /**
     * Asynchronously create a directory.
     *
     * This function creates a new directory at the specified path with optional
     * configuration for permissions and recursive creation. If parent directories
     * don't exist, they can be automatically created when the recursive option
     * is enabled, making it easy to create deep directory structures in one operation.
     * This is a convenience wrapper around File::createDirectory().
     *
     * @param  string  $path  The path where the directory should be created
     * @param  array<string, mixed>  $options  Optional configuration options:
     *                                         - 'mode' => int: Directory permissions in octal format (default: 0755)
     *                                         - 'recursive' => bool: Create parent directories if they don't exist (default: false)
     *                                         - 'context' => resource: Stream context for advanced directory creation options
     * @return CancellablePromiseInterface<bool> Promise that resolves with true on successful directory creation
     *
     * @throws RuntimeException If the directory already exists, parent directories don't exist (when not recursive), or insufficient permissions
     *
     * ```php
     * // Create a simple directory
     * $created = await(create_directory_async('/path/to/new/dir'));
     *
     * // Create directory recursively with custom permissions
     * $created = await(create_directory_async('/deep/nested/structure', [
     *     'recursive' => true,
     *     'mode' => 0755
     * ]));
     *
     * // Create directory with specific permissions
     * $created = await(create_directory_async('/secure/dir', ['mode' => 0700]));
     * ```
     */
    function create_directory_async(string $path, array $options = []): CancellablePromiseInterface
    {
        return File::createDirectory($path, $options);
    }
}

if (! function_exists('remove_directory_async')) {
    /**
     * Asynchronously remove an empty directory.
     *
     * This function removes a directory from the filesystem. The directory must be
     * completely empty (no files or subdirectories) before it can be removed.
     * For recursive directory removal, all contents must be deleted first using
     * other file and directory operations. This is a convenience wrapper around File::removeDirectory().
     *
     * @param  string  $path  The path to the directory to remove
     * @return CancellablePromiseInterface<bool> Promise that resolves with true on successful directory removal
     *
     * @throws RuntimeException If the directory doesn't exist, is not empty, or cannot be removed due to permissions
     *
     * ```php
     * // Remove an empty directory
     * $removed = await(remove_directory_async('/path/to/empty/dir'));
     * if ($removed) {
     *     echo "Directory removed successfully";
     * }
     *
     * // Remove directory with error handling
     * try {
     *     $removed = await(remove_directory_async('/path/to/dir'));
     * } catch (\RuntimeException $e) {
     *     echo "Failed to remove directory: " . $e->getMessage();
     * }
     * ```
     */
    function remove_directory_async(string $path): CancellablePromiseInterface
    {
        return File::removeDirectory($path);
    }
}

if (! function_exists('mkdir_recursive_async')) {
    /**
     * Asynchronously create a directory recursively.
     *
     * This function creates a directory and all necessary parent directories without blocking
     * the event loop. This is a convenience function for recursive directory creation
     * that automatically enables the recursive option and sets permissions.
     * This is a convenience wrapper around File::createDirectory().
     *
     * @param  string  $path  The directory path to create
     * @param  int  $permissions  Directory permissions in octal format (default: 0755)
     * @return CancellablePromiseInterface<bool> Promise that resolves with true on successful creation
     *
     * @throws RuntimeException If the directory cannot be created or insufficient permissions
     *
     * ```php
     * // Create deep directory structure with default permissions
     * $created = await(mkdir_recursive_async('/deep/nested/path/structure'));
     *
     * // Create with custom permissions
     * $created = await(mkdir_recursive_async('/secure/nested/dir', 0700));
     *
     * // Create application directory structure
     * $created = await(mkdir_recursive_async('/app/storage/logs/daily', 0755));
     * ```
     */
    function mkdir_recursive_async(string $path, int $permissions = 0755): CancellablePromiseInterface
    {
        return File::createDirectory($path, ['recursive' => true, 'mode' => $permissions]);
    }
}

if (! function_exists('get_file_size_async')) {
    /**
     * Asynchronously get the size of a file.
     *
     * This function retrieves just the file size without blocking the event loop.
     * This is a convenience function that extracts size from file stats, providing
     * a simpler interface when only the file size is needed rather than complete
     * file statistics.
     *
     * @param  string  $path  The file path to get size for
     * @return CancellablePromiseInterface<array<string, mixed>> Promise that resolves with detailed file information:
     *
     * @throws RuntimeException If the file doesn't exist or statistics cannot be retrieved
     *
     * ```php
     * // Get file size for validation
     * $size = await(get_file_size_async('/path/to/file.txt'));
     * if ($size > 1024 * 1024) { // 1MB
     *     echo "File is larger than 1MB: " . round($size / 1024 / 1024, 2) . "MB";
     * }
     *
     * // Check if file is empty
     * $size = await(get_file_size_async('/path/to/log.txt'));
     * if ($size === 0) {
     *     echo "Log file is empty";
     * }
     * ```
     */
    function get_file_stats_async(string $path): CancellablePromiseInterface
    {
        return File::stats($path);
    }
}

if (! function_exists('read_file_stream_async')) {
    /**
     * Asynchronously open a file for streaming read operations.
     *
     * This function returns a file stream resource that can be used for reading
     * large files efficiently without loading the entire content into memory.
     * This is particularly useful for processing large files, log files, or
     * when memory usage needs to be controlled. Remember to properly close
     * the stream resource when finished to prevent resource leaks.
     * This is a convenience wrapper around File::readFileStream().
     *
     * @param  string  $path  The path to the file to open for streaming reads
     * @param  array<string, mixed>  $options  Optional configuration options:
     *                                         - 'mode' => string: File open mode (default: 'r' for read-only)
     *                                         - 'buffer_size' => int: Size of the read buffer in bytes (default: 8192)
     *                                         - 'context' => resource: Stream context for advanced stream options
     *                                         - 'use_include_path' => bool: Search in include_path for the file
     * @return CancellablePromiseInterface<resource> Promise that resolves with a file stream resource handle
     *
     * @throws RuntimeException If the file cannot be opened, doesn't exist, or access is denied
     *
     * ```php
     * // Read large file in chunks
     * $stream = await(read_file_stream_async('/path/to/large-file.txt'));
     * while (!feof($stream)) {
     *     $chunk = fread($stream, 8192);
     *     // Process chunk
     *     echo "Processing " . strlen($chunk) . " bytes\n";
     * }
     * fclose($stream);
     *
     * // Read with custom buffer size
     * $stream = await(read_file_stream_async('/path/to/file.txt', [
     *     'buffer_size' => 16384 // 16KB buffer
     * ]));
     * ```
     */
    function read_file_stream_async(string $path, array $options = []): CancellablePromiseInterface
    {
        return File::readFileStream($path, $options);
    }
}

if (! function_exists('write_file_stream_async')) {
    /**
     * Asynchronously write data to a file using streaming operations.
     *
     * This function writes data to a file using streaming I/O, which is more
     * memory efficient for large amounts of data. The streaming approach processes
     * data in chunks rather than loading everything into memory at once, making
     * it suitable for handling very large datasets. The target file will be
     * created if it doesn't exist or truncated if it does.
     * This is a convenience wrapper around File::writeFileStream().
     *
     * @param  string  $path  The path where the file should be written
     * @param  string  $data  The data to write to the file using streaming
     * @param  array<string, mixed>  $options  Optional configuration options:
     *                                         - 'mode' => string: File write mode (default: 'w' for write/truncate)
     *                                         - 'buffer_size' => int: Size of the write buffer in bytes (default: 8192)
     *                                         - 'create_dirs' => bool: Create parent directories if they don't exist
     *                                         - 'context' => resource: Stream context for advanced stream options
     *                                         - 'chunk_size' => int: Size of data chunks to process at once
     * @return CancellablePromiseInterface<int> Promise that resolves with the total number of bytes written
     *
     * @throws RuntimeException If the file cannot be written, directory cannot be created, or streaming fails
     *
     * ```php
     * // Write large dataset using streaming
     * $largeData = generateLargeDataSet(); // Assume this generates lots of data
     * $bytesWritten = await(write_file_stream_async('/path/to/output.txt', $largeData));
     * echo "Successfully wrote $bytesWritten bytes using streaming";
     *
     * // Write with custom chunk size and directory creation
     * $bytesWritten = await(write_file_stream_async('/new/dir/file.txt', $data, [
     *     'chunk_size' => 16384,
     *     'create_dirs' => true
     * ]));
     * ```
     */
    function write_file_stream_async(string $path, string $data, array $options = []): CancellablePromiseInterface
    {
        return File::writeFileStream($path, $data, $options);
    }
}

if (! function_exists('copy_file_stream_async')) {
    /**
     * Asynchronously copy a file using streaming operations for memory efficiency.
     *
     * This function copies a file from source to destination using streaming I/O
     * to handle large files efficiently without consuming excessive memory. The
     * file is copied in chunks, making it suitable for very large files that
     * wouldn't fit comfortably in memory. Parent directories will be created
     * automatically if needed for the destination path.
     * This is a convenience wrapper around File::copyFileStream().
     *
     * @param  string  $source  The path to the source file to copy
     * @param  string  $destination  The path where the file copy should be created
     * @return CancellablePromiseInterface<bool> Promise that resolves with true on successful streaming copy
     *
     * @throws RuntimeException If the source file doesn't exist, destination cannot be written, or streaming copy fails
     *
     * ```php
     * // Copy a large video file efficiently
     * $copied = await(copy_file_stream_async('/media/large-video.mp4', '/backup/large-video.mp4'));
     * if ($copied) {
     *     echo "Large file copied successfully using streaming";
     * }
     *
     * // Copy database backup file
     * $copied = await(copy_file_stream_async('/backups/database.sql', '/archive/database.sql'));
     *
     * // Copy with error handling
     * try {
     *     $copied = await(copy_file_stream_async('/src/huge-file.dat', '/dest/huge-file.dat'));
     * } catch (\RuntimeException $e) {
     *     echo "Streaming copy failed: " . $e->getMessage();
     * }
     * ```
     */
    function copy_file_stream_async(string $source, string $destination): CancellablePromiseInterface
    {
        return File::copyFileStream($source, $destination);
    }
}
