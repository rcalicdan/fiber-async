<?php

namespace Rcalicdan\FiberAsync\Http;

use Rcalicdan\FiberAsync\Http\Interfaces\MessageInterface;
use Rcalicdan\FiberAsync\Http\Interfaces\StreamInterface;

abstract class Message implements MessageInterface
{
    protected string $protocol = '1.1';
    protected array $headers = [];
    protected array $headerNames = [];
    protected StreamInterface $body;

    public function getProtocolVersion(): string
    {
        return $this->protocol;
    }

    public function withProtocolVersion(string $version): MessageInterface
    {
        if ($this->protocol === $version) {
            return $this;
        }

        $new = clone $this;
        $new->protocol = $version;
        return $new;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headerNames[strtolower($name)]);
    }

    public function getHeader(string $name): array
    {
        $header = strtolower($name);
        if (!isset($this->headerNames[$header])) {
            return [];
        }

        $header = $this->headerNames[$header];
        return $this->headers[$header];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function withHeader(string $name, $value): MessageInterface
    {
        $value = $this->normalizeHeaderValue($value);
        $normalized = strtolower($name);

        $new = clone $this;
        if (isset($new->headerNames[$normalized])) {
            unset($new->headers[$new->headerNames[$normalized]]);
        }
        $new->headerNames[$normalized] = $name;
        $new->headers[$name] = $value;

        return $new;
    }

    public function withAddedHeader(string $name, $value): MessageInterface
    {
        if (!$this->hasHeader($name)) {
            return $this->withHeader($name, $value);
        }

        $header = $this->headerNames[strtolower($name)];
        $new = clone $this;
        $new->headers[$header] = array_merge($this->headers[$header], $this->normalizeHeaderValue($value));

        return $new;
    }

    public function withoutHeader(string $name): MessageInterface
    {
        $normalized = strtolower($name);
        if (!isset($this->headerNames[$normalized])) {
            return $this;
        }

        $header = $this->headerNames[$normalized];
        $new = clone $this;
        unset($new->headers[$header], $new->headerNames[$normalized]);

        return $new;
    }

    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    public function withBody(StreamInterface $body): MessageInterface
    {
        if ($body === $this->body) {
            return $this;
        }

        $new = clone $this;
        $new->body = $body;
        return $new;
    }

    protected function setHeaders(array $headers): void
    {
        $this->headerNames = [];
        $this->headers = [];

        foreach ($headers as $header => $value) {
            if (is_int($header)) {
                $header = (string) $header;
            }
            $value = $this->normalizeHeaderValue($value);
            $normalized = strtolower($header);
            if (isset($this->headerNames[$normalized])) {
                $header = $this->headerNames[$normalized];
                $this->headers[$header] = array_merge($this->headers[$header], $value);
            } else {
                $this->headerNames[$normalized] = $header;
                $this->headers[$header] = $value;
            }
        }
    }

    private function normalizeHeaderValue($value): array
    {
        if (!is_array($value)) {
            return [trim((string) $value)];
        }

        if (count($value) === 0) {
            throw new \InvalidArgumentException('Header value must be a string or non-empty array.');
        }

        return array_map(function ($v) {
            return trim((string) $v);
        }, array_values($value));
    }
}