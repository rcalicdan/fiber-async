<?php

namespace FiberAsync\ValueObjects;

use FiberAsync\Interfaces\HttpRequestInterface;

class HttpRequest implements HttpRequestInterface
{
    private \CurlHandle $handle;
    /** @var callable */
    private $callback;
    private string $url;

    public function __construct(string $url, array $options, callable $callback)
    {
        $this->url = $url;
        $this->callback = $callback;
        $this->handle = $this->createCurlHandle($url, $options);
    }

    private function createCurlHandle(string $url, array $options): \CurlHandle
    {
        $handle = curl_init();

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $options['timeout'] ?? 30,
            CURLOPT_CONNECTTIMEOUT => $options['connect_timeout'] ?? 10,
            CURLOPT_USERAGENT => $options['user_agent'] ?? 'PHP-Async-Client/1.0',
            CURLOPT_SSL_VERIFYPEER => $options['verify_ssl'] ?? true,
        ]);

        if (isset($options['method']) && $options['method'] === 'POST') {
            curl_setopt($handle, CURLOPT_POST, true);
            if (isset($options['data'])) {
                curl_setopt($handle, CURLOPT_POSTFIELDS, $options['data']);
            }
        }

        if (isset($options['headers'])) {
            curl_setopt($handle, CURLOPT_HTTPHEADER, $options['headers']);
        }

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

    public function executeCallback(?string $error, ?string $response, ?int $httpCode): void
    {
        ($this->callback)($error, $response, $httpCode);
    }
}
