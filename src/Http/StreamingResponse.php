<?php

namespace Rcalicdan\FiberAsync\Http;

use Rcalicdan\FiberAsync\Http\Interfaces\StreamInterface;

/**
 * StreamingResponse class for handling HTTP responses with streaming capabilities.
 * 
 * This class extends the base Response class to provide streaming functionality,
 * allowing for efficient handling of large response bodies without loading
 * the entire content into memory at once.
 * 
 * @package Rcalicdan\FiberAsync\Http
 */
class StreamingResponse extends Response
{
    /**
     * Default chunk size for reading streams in bytes (8KB).
     */
    private const CHUNK_SIZE = 8192;

    /**
     * The stream interface for reading response data.
     */
    private StreamInterface $stream;

    /**
     * Flag to track whether the stream has been consumed.
     */
    private bool $streamConsumed = false;

    /**
     * Constructor for StreamingResponse.
     *
     * @param StreamInterface $stream The stream containing the response body
     * @param int $status The HTTP status code
     * @param array<string, string|string[]> $headers Optional HTTP headers
     */
    public function __construct(StreamInterface $stream, int $status, array $headers = [])
    {
        $this->stream = $stream;
        parent::__construct($stream, $status, $headers);
    }

    /**
     * Get the underlying stream interface.
     *
     * @return StreamInterface The stream interface for this response
     */
    public function getStream(): StreamInterface
    {
        return $this->stream;
    }

    /**
     * Get the response body as a string.
     * 
     * This method consumes the stream if it hasn't been consumed already.
     * Once consumed, subsequent calls will return the cached content.
     * The stream content is stored in a temporary stream for future access.
     *
     * @return string The complete response body as a string
     * @throws \RuntimeException If temporary stream creation fails
     */
    public function body(): string
    {
        if ($this->streamConsumed) {
            return (string) $this->body;
        }

        $content = $this->stream->getContents();
        $this->streamConsumed = true;

        // Update the body stream with the consumed content
        $resource = fopen('php://temp', 'r+');
        if ($resource === false) {
            throw new \RuntimeException('Failed to open temporary stream');
        }
        
        fwrite($resource, $content);
        rewind($resource);
        $this->body = new Stream($resource);

        return $content;
    }

    /**
     * Parse the response body as JSON and return as an array.
     * 
     * This method attempts to decode the response body as JSON.
     * If the JSON is invalid or the result is not an array, an empty array is returned.
     *
     * @return array<mixed> The decoded JSON as an associative array, or empty array on failure
     */
    public function json(): array
    {
        $decoded = json_decode($this->body(), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Save the stream contents directly to a file.
     * 
     * This method streams the response body directly to a file without loading
     * the entire content into memory, making it efficient for large files.
     * The stream is rewound if seekable before reading.
     *
     * @param string $path The file path where the content should be saved
     * @return bool True on success, false on failure
     */
    public function saveToFile(string $path): bool
    {
        $file = fopen($path, 'wb');
        if ($file === false) {
            return false;
        }

        try {
            if ($this->stream->isSeekable()) {
                $this->stream->rewind();
            }

            while (! $this->stream->eof()) {
                $chunk = $this->stream->read(self::CHUNK_SIZE);
                if ($chunk === '') {
                    break;
                }
                fwrite($file, $chunk);
            }

            return true;
        } finally {
            fclose($file);
        }
    }

    /**
     * Stream the response contents to a destination.
     * 
     * This method can stream the response body to either a file (by path)
     * or to an existing resource handle. This is memory-efficient as it
     * processes the stream in chunks rather than loading everything into memory.
     * 
     * If the destination is a string, it's treated as a file path.
     * If the destination is a resource, the content is written directly to it.
     * The stream is rewound if seekable before streaming begins.
     *
     * @param string|resource $destination File path or resource handle to stream to
     * @return bool True on success, false on failure or invalid destination
     */
    public function streamTo($destination): bool
    {
        if (is_string($destination)) {
            return $this->saveToFile($destination);
        }

        if (! is_resource($destination)) {
            return false;
        }

        try {
            if ($this->stream->isSeekable()) {
                $this->stream->rewind();
            }

            while (! $this->stream->eof()) {
                $chunk = $this->stream->read(self::CHUNK_SIZE);
                if ($chunk === '') {
                    break;
                }
                fwrite($destination, $chunk);
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}