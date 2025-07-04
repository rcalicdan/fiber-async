<?php

namespace Rcalicdan\FiberAsync\Http;

class Response
{
    private string $body;
    private int $status;
    private array $headers;
    private array $parsedHeaders = [];

    public function __construct(string $body, int $status, array $headers = [])
    {
        $this->body = $body;
        $this->status = $status;
        $this->headers = $headers;
        $this->parseHeaders();
    }

    public function body(): string
    {
        return $this->body;
    }

    public function json(): array
    {
        return json_decode($this->body, true) ?? [];
    }

    public function status(): int
    {
        return $this->status;
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function headers(): array
    {
        return $this->parsedHeaders;
    }

    public function header(string $name): ?string
    {
        return $this->parsedHeaders[strtolower($name)] ?? null;
    }

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function failed(): bool
    {
        return ! $this->successful();
    }

    public function clientError(): bool
    {
        return $this->status >= 400 && $this->status < 500;
    }

    public function serverError(): bool
    {
        return $this->status >= 500;
    }

    private function parseHeaders(): void
    {
        foreach ($this->headers as $header) {
            if (strpos($header, ':') !== false) {
                [$name, $value] = explode(':', $header, 2);
                $this->parsedHeaders[strtolower(trim($name))] = trim($value);
            }
        }
    }
}
