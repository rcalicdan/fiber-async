<?php

namespace TrueAsync\Interfaces;

interface HttpRequestInterface
{
    public function getHandle(): \CurlHandle;
    public function getCallback(): callable;
    public function getUrl(): string;
    public function executeCallback(?string $error, ?string $response, ?int $httpCode): void;
}