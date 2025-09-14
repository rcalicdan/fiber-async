<?php

namespace Rcalicdan\FiberAsync\Api;

use Rcalicdan\FiberAsync\Async\AsyncOperations;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Rcalicdan\FiberAsync\Stream\AsyncStreamOperations;
use Rcalicdan\FiberAsync\Stream\Exceptions\StreamException;
use Rcalicdan\FiberAsync\Stream\Exceptions\TimeoutException;

/**
 * AsyncStream provides a fluent interface for asynchronous stream operations.
 *
 * This class serves as a facade for AsyncStreamOperations, providing static
 * methods for common stream operations while maintaining a singleton pattern
 * for the underlying operations instance.
 */
final class AsyncStream
{
    /**
     * Singleton instance of AsyncStreamOperations.
     */
    private static ?AsyncStreamOperations $ops = null;

    /**
     * Get the singleton instance of AsyncStreamOperations.
     *
     * Creates a new instance if one doesn't exist yet.
     *
     * @return AsyncStreamOperations The singleton instance
     */
    protected static function getInstance(): AsyncStreamOperations
    {
        if (self::$ops === null) {
            self::$ops = new AsyncStreamOperations;
        }

        return self::$ops;
    }

    /**
     * Reset the singleton instance.
     *
     * This method clears the current instance, allowing a fresh instance
     * to be created on the next call to getInstance().
     */
    public static function reset(): void
    {
        self::$ops = null;
    }

    /**
     * Get the async operations helper instance.
     *
     * @return AsyncOperations The async operations instance
     */
    public static function getAsyncOps(): AsyncOperations
    {
        return self::getInstance()->getAsyncOps();
    }

    /**
     * Open a stream resource asynchronously.
     *
     * Creates a non-blocking stream that resolves when the stream
     * is ready for operations or rejects if opening fails or times out.
     *
     * @param  string  $filename  The file or URL to open
     * @param  string  $mode  The mode for opening the stream (e.g., 'r', 'w', 'a')
     * @param  float|null  $timeout  Open timeout in seconds. Null or 0 for no timeout. Defaults to 10.0 seconds
     * @param  array  $contextOptions  Additional context options for the stream
     * @return PromiseInterface<resource> A promise that resolves with a stream resource on successful opening
     *
     * @throws StreamException If the stream cannot be opened
     * @throws TimeoutException If the open operation times out
     */
    public static function open(string $filename, string $mode = 'r', ?float $timeout = 10.0, array $contextOptions = []): PromiseInterface
    {
        return self::getInstance()->open($filename, $mode, $timeout, $contextOptions);
    }

    /**
     * Read data asynchronously from a stream.
     *
     * Reads up to the specified number of bytes from the stream. The promise
     * resolves when data is available or the stream reaches EOF.
     *
     * @param  resource  $stream  The stream to read from
     * @param  int|null  $length  Maximum number of bytes to read (default: 8192)
     * @param  float|null  $timeout  Read timeout in seconds. Null or 0 for no timeout. Defaults to 10.0 seconds
     * @return PromiseInterface<string|null> A promise that resolves with the data read, or null if stream is at EOF
     *
     * @throws StreamException If the stream is closed or reading fails
     * @throws TimeoutException If the read operation times out
     */
    public static function read($stream, ?int $length = null, ?float $timeout = 10.0): PromiseInterface
    {
        return self::getInstance()->read($stream, $length, $timeout);
    }

    /**
     * Read a line asynchronously from a stream.
     *
     * Reads data until a newline character is encountered or the specified
     * length is reached. The promise resolves when a complete line is available.
     *
     * @param  resource  $stream  The stream to read from
     * @param  int|null  $length  Maximum number of bytes to read (default: 8192)
     * @param  float|null  $timeout  Read timeout in seconds. Null or 0 for no timeout. Defaults to 10.0 seconds
     * @return PromiseInterface<string|null> A promise that resolves with the line read, or null if stream is at EOF
     *
     * @throws StreamException If the stream is closed or reading fails
     * @throws TimeoutException If the read operation times out
     */
    public static function readLine($stream, ?int $length = null, ?float $timeout = 10.0): PromiseInterface
    {
        return self::getInstance()->readLine($stream, $length, $timeout);
    }

    /**
     * Read all data asynchronously from a stream.
     *
     * Continues reading data until EOF is reached. The promise resolves
     * with all data read from the stream.
     *
     * @param  resource  $stream  The stream to read from
     * @param  int  $maxLength  Maximum total bytes to read (default: 1MB)
     * @param  float|null  $timeout  Read timeout in seconds. Null or 0 for no timeout. Defaults to 30.0 seconds
     * @return PromiseInterface<string> A promise that resolves with all data read from the stream
     *
     * @throws StreamException If the stream is closed or reading fails
     * @throws TimeoutException If the read operation times out
     */
    public static function readAll($stream, int $maxLength = 1048576, ?float $timeout = 30.0): PromiseInterface
    {
        return self::getInstance()->readAll($stream, $maxLength, $timeout);
    }

    /**
     * Write data asynchronously to a stream.
     *
     * Writes the specified data to the stream. Handles partial writes by
     * recursively continuing until all data is written.
     *
     * @param  resource  $stream  The stream to write to
     * @param  string  $data  The data to write
     * @param  float|null  $timeout  Write timeout in seconds. Null or 0 for no timeout. Defaults to 10.0 seconds
     * @return PromiseInterface<int> A promise that resolves with the number of bytes written
     *
     * @throws StreamException If the stream is closed or writing fails
     * @throws TimeoutException If the write operation times out
     */
    public static function write($stream, string $data, ?float $timeout = 10.0): PromiseInterface
    {
        return self::getInstance()->write($stream, $data, $timeout);
    }

    /**
     * Write a line asynchronously to a stream.
     *
     * Appends a newline character to the data and writes it to the stream.
     *
     * @param  resource  $stream  The stream to write to
     * @param  string  $data  The data to write
     * @param  float|null  $timeout  Write timeout in seconds. Null or 0 for no timeout. Defaults to 10.0 seconds
     * @return PromiseInterface<int> A promise that resolves with the number of bytes written
     *
     * @throws StreamException If the stream is closed or writing fails
     * @throws TimeoutException If the write operation times out
     */
    public static function writeLine($stream, string $data, ?float $timeout = 10.0): PromiseInterface
    {
        return self::getInstance()->writeLine($stream, $data, $timeout);
    }

    /**
     * Seek to a position in a stream asynchronously.
     *
     * Moves the stream pointer to the specified position.
     *
     * @param  resource  $stream  The stream to seek in
     * @param  int  $offset  The offset to seek to
     * @param  int  $whence  How to interpret the offset (SEEK_SET, SEEK_CUR, or SEEK_END)
     * @return PromiseInterface<int> A promise that resolves with the new stream position
     *
     * @throws StreamException If the stream doesn't support seeking or seeking fails
     */
    public static function seek($stream, int $offset, int $whence = SEEK_SET): PromiseInterface
    {
        return self::getInstance()->seek($stream, $offset, $whence);
    }

    /**
     * Get the current position in a stream asynchronously.
     *
     * @param  resource  $stream  The stream to get position from
     * @return PromiseInterface<int> A promise that resolves with the current stream position
     *
     * @throws StreamException If getting the position fails
     */
    public static function tell($stream): PromiseInterface
    {
        return self::getInstance()->tell($stream);
    }

    /**
     * Check if a stream is at EOF asynchronously.
     *
     * @param  resource  $stream  The stream to check
     * @return PromiseInterface<bool> A promise that resolves with true if at EOF, false otherwise
     *
     * @throws StreamException If checking EOF status fails
     */
    public static function eof($stream): PromiseInterface
    {
        return self::getInstance()->eof($stream);
    }

    /**
     * Flush a stream asynchronously.
     *
     * Forces any buffered data to be written to the underlying storage.
     *
     * @param  resource  $stream  The stream to flush
     * @param  float|null  $timeout  Flush timeout in seconds. Null or 0 for no timeout. Defaults to 10.0 seconds
     * @return PromiseInterface<bool> A promise that resolves with true on successful flush
     *
     * @throws StreamException If flushing fails
     * @throws TimeoutException If the flush operation times out
     */
    public static function flush($stream, ?float $timeout = 10.0): PromiseInterface
    {
        return self::getInstance()->flush($stream, $timeout);
    }

    /**
     * Close a stream resource.
     *
     * Closes the stream and removes all event loop watchers
     * associated with the stream to prevent memory leaks.
     *
     * @param  resource  $stream  The stream to close
     * @return bool True if the stream was successfully closed
     */
    public static function close($stream): bool
    {
        return self::getInstance()->close($stream);
    }

    /**
     * Copy data from one stream to another asynchronously.
     *
     * @param  resource  $source  The source stream to copy from
     * @param  resource  $destination  The destination stream to copy to
     * @param  int|null  $maxBytes  Maximum number of bytes to copy (null for all)
     * @param  float|null  $timeout  Copy timeout in seconds. Null or 0 for no timeout. Defaults to 30.0 seconds
     * @return PromiseInterface<int> A promise that resolves with the number of bytes copied
     *
     * @throws StreamException If copying fails
     * @throws TimeoutException If the copy operation times out
     */
    public static function copy($source, $destination, ?int $maxBytes = null, ?float $timeout = 30.0): PromiseInterface
    {
        return self::getInstance()->copy($source, $destination, $maxBytes, $timeout);
    }

    /**
     * Get stream metadata asynchronously.
     *
     * @param  resource  $stream  The stream to get metadata from
     * @return PromiseInterface<array> A promise that resolves with stream metadata
     *
     * @throws StreamException If getting metadata fails
     */
    public static function getMetadata($stream): PromiseInterface
    {
        return self::getInstance()->getMetadata($stream);
    }

    /**
     * Set stream blocking mode.
     *
     * @param  resource  $stream  The stream to modify
     * @param  bool  $blocking  Whether the stream should be blocking
     * @return bool True if the blocking mode was set successfully
     */
    public static function setBlocking($stream, bool $blocking = false): bool
    {
        return self::getInstance()->setBlocking($stream, $blocking);
    }

    /**
     * Set stream timeout.
     *
     * @param  resource  $stream  The stream to modify
     * @param  int  $seconds  Timeout in seconds
     * @param  int  $microseconds  Additional microseconds for timeout
     * @return bool True if the timeout was set successfully
     */
    public static function setTimeout($stream, int $seconds, int $microseconds = 0): bool
    {
        return self::getInstance()->setTimeout($stream, $seconds, $microseconds);
    }
}