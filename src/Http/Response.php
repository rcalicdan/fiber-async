<?php

namespace Rcalicdan\FiberAsync\Http;

/**
 * Represents an immutable HTTP response.
 *
 * This class encapsulates the response body, status code, and headers from
 * an HTTP request, providing convenient methods to access and interpret the data.
 *
 * @psalm-immutable
 */
class Response
{
    /**
     * The raw HTTP response body.
     * @var string
     */
    protected string $body;

    /**
     * The HTTP status code.
     * @var int
     */
    protected int $status;

    /**
     * The raw, unprocessed headers from the HTTP response.
     * @var array
     */
    protected array $headers;

    /**
     * The parsed, associative array of headers (lowercase keys).
     * @var array<string, string>
     */
    private array $parsedHeaders = [];

    /**
     * Initializes the Response object.
     *
     * @param string $body The raw response body.
     * @param int $status The HTTP status code.
     * @param array $headers The raw array of header strings.
     */
    public function __construct(string $body, int $status, array $headers = [])
    {
        $this->body = $body;
        $this->status = $status;
        $this->headers = $headers;
        $this->parseHeaders();
    }

    /**
     * Gets the raw response body as a string.
     *
     * @return string
     */
    public function body(): string
    {
        return $this->body;
    }

    /**
     * Gets the raw response body as a string. (Alias for body())
     *
     * @return string
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Decodes the JSON response body into an associative array.
     * Returns an empty array if the body is not valid JSON.
     *
     * @return array
     */
    public function json(): array
    {
        return json_decode($this->body, true) ?? [];
    }

    /**
     * Decodes the JSON response body into an associative array. (Alias for json())
     * Returns an empty array if the body is not valid JSON.
     *
     * @return array
     */
    public function getJson(): array
    {
        return json_decode($this->body, true) ?? [];
    }

    /**
     * Gets the HTTP status code.
     *
     * @return int
     */
    public function status(): int
    {
        return $this->status;
    }

    /**
     * Gets the HTTP status code. (Alias for status())
     *
     * @return int
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * Gets all parsed response headers as an associative array.
     * Header names are normalized to lowercase.
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->parsedHeaders;
    }

    /**
     * Gets a single header value by name.
     * The lookup is case-insensitive. Returns null if the header is not found.
     *
     * @param string $name The name of the header.
     * @return string|null
     */
    public function getHeader(string $name): ?string
    {
        return $this->parsedHeaders[strtolower($name)] ?? null;
    }

    /**
     * Checks if the response was successful (status code 200-299).
     *
     * @return bool
     */
    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Gets all parsed response headers as an associative array. (Alias for getHeaders())
     * Header names are normalized to lowercase.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->parsedHeaders;
    }

    /**
     * Gets a single header value by name. (Alias for getHeader())
     * The lookup is case-insensitive. Returns null if the header is not found.
     *
     * @param string $name The name of the header.
     * @return string|null
     */
    public function header(string $name): ?string
    {
        return $this->parsedHeaders[strtolower($name)] ?? null;
    }

    /**
     * Checks if the response was successful (status code 200-299). (Alias for ok())
     *
     * @return bool
     */
    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Checks if the response was not successful (status code is not 2xx).
     *
     * @return bool
     */
    public function failed(): bool
    {
        return ! $this->successful();
    }

    /**
     * Checks if the response indicates a client error (status code 400-499).
     *
     * @return bool
     */
    public function clientError(): bool
    {
        return $this->status >= 400 && $this->status < 500;
    }

    /**
     * Checks if the response indicates a server error (status code 500-599).
     *
     * @return bool
     */
    public function serverError(): bool
    {
        return $this->status >= 500;
    }

    /**
     * Parses the raw header array into a lowercase, associative array.
     * This is called automatically by the constructor.
     *
     * @return void
     */
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
