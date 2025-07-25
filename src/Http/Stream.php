<?php

namespace Rcalicdan\FiberAsync\Http;

use Rcalicdan\FiberAsync\Http\Interfaces\StreamInterface;
use RuntimeException;

class Stream implements StreamInterface
{
    private $resource;
    private ?string $uri;
    private bool $seekable;
    private bool $readable;
    private bool $writable;
    private ?int $size = null;

    public function __construct($resource, ?string $uri = null)
    {
        if (! is_resource($resource)) {
            throw new RuntimeException('Stream must be a resource');
        }

        $this->resource = $resource;
        $this->uri = $uri;

        $meta = stream_get_meta_data($this->resource);
        $this->seekable = $meta['seekable'] ?? false;
        $this->readable = $this->checkReadable($meta['mode'] ?? 'r');
        $this->writable = $this->checkWritable($meta['mode'] ?? 'r');
    }

    public function __toString(): string
    {
        if (! $this->isReadable()) {
            return '';
        }

        try {
            if ($this->isSeekable()) {
                $this->rewind();
            }

            return $this->getContents();
        } catch (\Exception $e) {
            return '';
        }
    }

    public function close(): void
    {
        if (is_resource($this->resource)) {
            fclose($this->resource);
        }
        $this->detach();
    }

    public function detach()
    {
        if (! is_resource($this->resource)) {
            return null;
        }

        $result = $this->resource;
        $this->resource = null;
        $this->size = null;
        $this->uri = null;
        $this->readable = false;
        $this->writable = false;
        $this->seekable = false;

        return $result;
    }

    public function getSize(): ?int
    {
        if ($this->size !== null) {
            return $this->size;
        }

        if (! is_resource($this->resource)) {
            return null;
        }

        $stats = fstat($this->resource);
        if (isset($stats['size'])) {
            $this->size = $stats['size'];

            return $this->size;
        }

        return null;
    }

    public function tell(): int
    {
        if (! is_resource($this->resource)) {
            throw new RuntimeException('Stream is detached');
        }

        $result = ftell($this->resource);
        if ($result === false) {
            throw new RuntimeException('Unable to determine stream position');
        }

        return $result;
    }

    public function eof(): bool
    {
        if (! is_resource($this->resource)) {
            return true;
        }

        return feof($this->resource);
    }

    public function isSeekable(): bool
    {
        return $this->seekable;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if (! $this->isSeekable()) {
            throw new RuntimeException('Stream is not seekable');
        }

        if (fseek($this->resource, $offset, $whence) === -1) {
            throw new RuntimeException('Unable to seek to stream position');
        }
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return $this->writable;
    }

    public function write(string $string): int
    {
        if (! $this->isWritable()) {
            throw new RuntimeException('Cannot write to a non-writable stream');
        }

        $result = fwrite($this->resource, $string);
        if ($result === false) {
            throw new RuntimeException('Unable to write to stream');
        }

        return $result;
    }

    public function isReadable(): bool
    {
        return $this->readable;
    }

    public function read(int $length): string
    {
        if (! $this->isReadable()) {
            throw new RuntimeException('Cannot read from non-readable stream');
        }

        if ($length < 0) {
            throw new RuntimeException('Length parameter cannot be negative');
        }

        if ($length === 0) {
            return '';
        }

        $result = fread($this->resource, $length);
        if ($result === false) {
            throw new RuntimeException('Unable to read from stream');
        }

        return $result;
    }

    public function getContents(): string
    {
        if (! $this->isReadable()) {
            throw new RuntimeException('Cannot read from non-readable stream');
        }

        $contents = stream_get_contents($this->resource);
        if ($contents === false) {
            throw new RuntimeException('Unable to read stream contents');
        }

        return $contents;
    }

    public function getMetadata(?string $key = null)
    {
        if (! is_resource($this->resource)) {
            return $key ? null : [];
        }

        $meta = stream_get_meta_data($this->resource);
        if ($key === null) {
            return $meta;
        }

        return $meta[$key] ?? null;
    }

    private function checkReadable(string $mode): bool
    {
        return strpbrk($mode, 'rwa+') !== false;
    }

    private function checkWritable(string $mode): bool
    {
        return strpbrk($mode, 'xwca+') !== false;
    }
}
