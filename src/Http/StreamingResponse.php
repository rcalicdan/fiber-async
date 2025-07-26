<?php

namespace Rcalicdan\FiberAsync\Http;

use Rcalicdan\FiberAsync\Http\Interfaces\StreamInterface;

class StreamingResponse extends Response
{
    private StreamInterface $stream;
    private bool $streamConsumed = false;

    public function __construct(StreamInterface $stream, int $status, array $headers = [])
    {
        $this->stream = $stream;
        parent::__construct($stream, $status, $headers);
    }

    public function getStream(): StreamInterface
    {
        return $this->stream;
    }

    public function body(): string
    {
        if ($this->streamConsumed) {
            return (string) $this->body;
        }

        $content = $this->stream->getContents();
        $this->streamConsumed = true;

        // Update the body stream with the consumed content
        $resource = fopen('php://temp', 'r+');
        fwrite($resource, $content);
        rewind($resource);
        $this->body = new Stream($resource);

        return $content;
    }

    public function json(): array
    {
        return json_decode($this->body(), true) ?? [];
    }

    public function saveToFile(string $path): bool
    {
        $file = fopen($path, 'wb');
        if (!$file) {
            return false;
        }

        try {
            if ($this->stream->isSeekable()) {
                $this->stream->rewind();
            }

            while (!$this->stream->eof()) {
                $chunk = $this->stream->read(8192);
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

    public function streamTo($destination): bool
    {
        if (is_string($destination)) {
            return $this->saveToFile($destination);
        }

        if (!is_resource($destination)) {
            return false;
        }

        try {
            if ($this->stream->isSeekable()) {
                $this->stream->rewind();
            }

            while (!$this->stream->eof()) {
                $chunk = $this->stream->read(8192);
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
