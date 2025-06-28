<?php

namespace Rcalicdan\FiberAsync\Handlers\AsyncOperations;

use Rcalicdan\FiberAsync\AsyncEventLoop;
use Rcalicdan\FiberAsync\AsyncPromise;
use Rcalicdan\FiberAsync\Bridges\HttpClientBridge;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Exception;

final readonly class HttpHandler
{
    public function fetch(string $url, array $options = []): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) use ($url, $options) {
            AsyncEventLoop::getInstance()->addHttpRequest($url, $options, function ($error, $response, $httpCode) use ($resolve, $reject) {
                if ($error) {
                    $reject(new Exception('HTTP Request failed: ' . $error));
                } else {
                    $resolve([
                        'body' => $response,
                        'status' => $httpCode,
                        'ok' => $httpCode >= 200 && $httpCode < 300,
                    ]);
                }
            });
        });
    }

    public function guzzle(string $method, string $url, array $options = []): PromiseInterface
    {
        return HttpClientBridge::getInstance()->guzzle($method, $url, $options);
    }

    public function http()
    {
        return HttpClientBridge::getInstance()->laravel();
    }

    public function wrapSync(callable $syncCall): PromiseInterface
    {
        return HttpClientBridge::getInstance()->wrap($syncCall);
    }
}
