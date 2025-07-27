<?php

namespace Rcalicdan\FiberAsync\EventLoop\ValueObjects;

use Rcalicdan\FiberAsync\Http\Interfaces\AsyncHttpRequestInterface;

class HttpRequest implements AsyncHttpRequestInterface
{
    private \CurlHandle $handle;
    private $callback;
    private string $url;
    private ?string $id = null;

    public function __construct(string $url, array $options, callable $callback)
    {
        $this->url = $url;
        $this->callback = $callback;
        $this->handle = $this->createCurlHandle($options);
    }

    private function createCurlHandle(array $options): \CurlHandle
    {
        $handle = curl_init();
        curl_setopt_array($handle, $options);

        return $handle;
    }

    public function getHandle(): \CurlHandle
    {
        return $this->handle;
    }

    public function getCallback(): callable
    {
        return $this->callback;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function executeCallback(?string $error, ?string $response, ?int $httpCode, array $headers = []): void
    {
        ($this->callback)($error, $response, $httpCode, $headers);
    }

    public function setId(string $id): void
    {
        $this->id = $id;
    }

    public function getId(): ?string
    {
        return $this->id;
    }
}
