<?php

namespace Rcalicdan\FiberAsync\Http;

use Rcalicdan\FiberAsync\Http\Interfaces\MessageInterface;
use Rcalicdan\FiberAsync\Http\Interfaces\StreamInterface;

abstract class Message implements MessageInterface
{
    /**
     * @var string The HTTP protocol version.
     */
    protected string $protocol = '1.1';

    /** @var array<string, string[]> HTTP headers. */
    protected array $headers = [];

    /** @var array<string, string> Map of lowercase header names to original case. */
    protected array $headerNames = [];

    /** @var StreamInterface The message body. */
    protected StreamInterface $body;

    /**
     * {@inheritdoc}
     */
    public function getProtocolVersion(): string
    {
        return $this->protocol;
    }

    /**
     * {@inheritdoc}
     */
    public function withProtocolVersion(string $version): MessageInterface
    {
        if ($this->protocol === $version) {
            return $this;
        }

        $new = clone $this;
        $new->protocol = $version;

        return $new;
    }

    /**
     * {@inheritdoc}
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * {@inheritdoc}
     */
    public function hasHeader(string $name): bool
    {
        return isset($this->headerNames[strtolower($name)]);
    }

    /**
     * {@inheritdoc}
     */
    public function getHeader(string $name): array
    {
        $header = strtolower($name);
        if (! isset($this->headerNames[$header])) {
            return [];
        }

        $header = $this->headerNames[$header];

        return $this->headers[$header];
    }

    /**
     * {@inheritdoc}
     */
    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    /**
     * {@inheritdoc}
     *
     * @param  string|string[]  $value
     */
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

    /**
     * {@inheritdoc}
     *
     * @param  string|string[]  $value
     */
    public function withAddedHeader(string $name, $value): MessageInterface
    {
        if (! $this->hasHeader($name)) {
            return $this->withHeader($name, $value);
        }

        $header = $this->headerNames[strtolower($name)];
        $new = clone $this;
        $new->headers[$header] = array_merge($this->headers[$header], $this->normalizeHeaderValue($value));

        return $new;
    }

    /**
     * {@inheritdoc}
     */
    public function withoutHeader(string $name): MessageInterface
    {
        $normalized = strtolower($name);
        if (! isset($this->headerNames[$normalized])) {
            return $this;
        }

        $header = $this->headerNames[$normalized];
        $new = clone $this;
        unset($new->headers[$header], $new->headerNames[$normalized]);

        return $new;
    }

    /**
     * {@inheritdoc}
     */
    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    /**
     * {@inheritdoc}
     */
    public function withBody(StreamInterface $body): MessageInterface
    {
        if ($body === $this->body) {
            return $this;
        }

        $new = clone $this;
        $new->body = $body;

        return $new;
    }

    /**
     * Set headers from an array.
     *
     * @param  array<string, string|string[]>  $headers
     */
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

    /**
     * Normalize a header value to an array of strings.
     *
     * @param  mixed  $value
     * @return string[]
     *
     * @throws \InvalidArgumentException
     */
    private function normalizeHeaderValue($value): array
    {
        if (! is_array($value)) {
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
