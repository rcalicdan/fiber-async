<?php

namespace Rcalicdan\FiberAsync\Http;

use Rcalicdan\FiberAsync\Http\Interfaces\ResponseInterface;
use Rcalicdan\FiberAsync\Http\Interfaces\StreamInterface;

class Response extends Message implements ResponseInterface
{
    private const PHRASES = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-status',
        208 => 'Already Reported',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        306 => 'Switch Proxy',
        307 => 'Temporary Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Time-out',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Request Entity Too Large',
        414 => 'Request-URI Too Large',
        415 => 'Unsupported Media Type',
        416 => 'Requested range not satisfiable',
        417 => 'Expectation Failed',
        418 => 'I\'m a teapot',
        422 => 'Unprocessable Entity',
        423 => 'Locked',
        424 => 'Failed Dependency',
        425 => 'Unordered Collection',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Time-out',
        505 => 'HTTP Version not supported',
        506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage',
        508 => 'Loop Detected',
        511 => 'Network Authentication Required',
    ];

    private int $statusCode;
    private string $reasonPhrase;

    public function __construct($body = 'php://memory', int $status = 200, array $headers = [])
    {
        $this->statusCode = $status;
        $this->reasonPhrase = self::PHRASES[$status] ?? 'Unknown Status Code';

        if (!($body instanceof StreamInterface)) {
            if (is_string($body)) {
                $resource = fopen('php://temp', 'r+');
                if ($body !== '') {
                    fwrite($resource, $body);
                    rewind($resource);
                }
                $body = new Stream($resource);
            } else {
                $body = new Stream(fopen('php://temp', 'r+'));
            }
        }

        $this->body = $body;
        $this->setHeaders($headers);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }

    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
    {
        if ($code < 100 || $code >= 600) {
            throw new \InvalidArgumentException('Status code must be an integer value between 1xx and 5xx.');
        }

        $new = clone $this;
        $new->statusCode = $code;
        if ($reasonPhrase === '' && isset(self::PHRASES[$code])) {
            $reasonPhrase = self::PHRASES[$code];
        }
        $new->reasonPhrase = $reasonPhrase;

        return $new;
    }

    public function body(): string
    {
        return (string) $this->body;
    }

    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    public function json(): array
    {
        return json_decode((string) $this->body, true) ?? [];
    }

    public function getJson(): array
    {
        return $this->json();
    }

    public function status(): int
    {
        return $this->statusCode;
    }

    public function getStatus(): int
    {
        return $this->statusCode;
    }

    public function headers(): array
    {
        $headers = [];
        foreach ($this->headers as $name => $values) {
            $headers[strtolower($name)] = is_array($values) ? implode(', ', $values) : $values;
        }
        return $headers;
    }

    public function header(string $name): ?string
    {
        $header = $this->getHeaderLine($name);
        return $header !== '' ? $header : null;
    }

    public function ok(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    public function successful(): bool
    {
        return $this->ok();
    }

    public function failed(): bool
    {
        return !$this->successful();
    }

    public function clientError(): bool
    {
        return $this->statusCode >= 400 && $this->statusCode < 500;
    }

    public function serverError(): bool
    {
        return $this->statusCode >= 500;
    }
}
