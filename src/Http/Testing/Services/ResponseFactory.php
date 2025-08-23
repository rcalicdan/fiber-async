<?php

namespace Rcalicdan\FiberAsync\Http\Testing\Services;

use Exception;
use Rcalicdan\FiberAsync\EventLoop\EventLoop;
use Rcalicdan\FiberAsync\Http\Exceptions\HttpException;
use Rcalicdan\FiberAsync\Http\Response;
use Rcalicdan\FiberAsync\Http\StreamingResponse;
use Rcalicdan\FiberAsync\Http\Testing\MockedRequest;
use Rcalicdan\FiberAsync\Promise\CancellablePromise;
use Rcalicdan\FiberAsync\Promise\Interfaces\CancellablePromiseInterface;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

class ResponseFactory
{
    private NetworkSimulator $networkSimulator;

    public function __construct(NetworkSimulator $networkSimulator)
    {
        $this->networkSimulator = $networkSimulator;
    }

    public function createMockedResponse(MockedRequest $mock): PromiseInterface
    {
        /** @var CancellablePromise<Response> $promise */
        $promise = new CancellablePromise();

        $this->executeWithNetworkSimulation($promise, $mock, function () use ($mock) {
            if ($mock->shouldFail()) {
                $error = $mock->getError();
                
                if ($mock->isRetryableFailure()) {
                    throw new HttpException($error);
                }
                
                throw new HttpException($error);
            }

            return new Response(
                $mock->getBody(),
                $mock->getStatusCode(),
                $mock->getHeaders()
            );
        });

        return $promise;
    }

    public function createMockedStream(MockedRequest $mock, ?callable $onChunk, callable $createStream): CancellablePromiseInterface
    {
        /** @var CancellablePromise<StreamingResponse> $promise */
        $promise = new CancellablePromise();

        $this->executeWithNetworkSimulation($promise, $mock, function () use ($mock, $onChunk, $createStream) {
            if ($mock->shouldFail()) {
                $error = $mock->getError();
                if ($mock->isRetryableFailure()) {
                    throw new HttpException($error);
                }
                throw new HttpException($error);
            }

            if ($onChunk !== null) {
                $body = $mock->getBody();
                $chunkSize = 1024;
                for ($i = 0; $i < strlen($body); $i += $chunkSize) {
                    $chunk = substr($body, $i, $chunkSize);
                    $onChunk($chunk);
                }
            }

            $stream = $createStream($mock->getBody());
            return new StreamingResponse(
                $stream,
                $mock->getStatusCode(),
                $mock->getHeaders()
            );
        });

        return $promise;
    }

    public function createMockedDownload(MockedRequest $mock, string $destination, FileManager $fileManager): CancellablePromiseInterface
    {
        /** @var CancellablePromise<array> $promise */
        $promise = new CancellablePromise();

        $this->executeWithNetworkSimulation($promise, $mock, function () use ($mock, $destination, $fileManager) {
            if ($mock->shouldFail()) {
                $error = $mock->getError();
                if ($mock->isRetryableFailure()) {
                    throw new HttpException($error);
                }
                throw new Exception($error);
            }

            $directory = dirname($destination);
            if (!is_dir($directory)) {
                if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
                    throw new Exception("Cannot create directory: {$directory}");
                }
                $fileManager->trackDirectory($directory);
            }

            $bytesWritten = file_put_contents($destination, $mock->getBody());
            if ($bytesWritten === false) {
                throw new Exception("Cannot write to file: {$destination}");
            }

            $fileManager->trackFile($destination);

            return [
                'file' => $destination,
                'status' => $mock->getStatusCode(),
                'headers' => $mock->getHeaders(),
                'size' => strlen($mock->getBody()),
                'protocol_version' => '2.0'
            ];
        });

        return $promise;
    }

    /**
     * Executes a callback with network simulation, handling timeouts and failures
     */
    private function executeWithNetworkSimulation(CancellablePromise $promise, MockedRequest $mock, callable $callback): void
    {
        $networkConditions = $this->networkSimulator->simulate();
        $totalDelay = max($mock->getDelay(), $networkConditions['delay']);

        $timerId = null;
        $isCancelled = false;

        $promise->setCancelHandler(function () use (&$timerId, &$isCancelled) {
            $isCancelled = true;
            if ($timerId !== null) {
                EventLoop::getInstance()->cancelTimer($timerId);
            }
        });

        if ($networkConditions['should_timeout']) {
            $timerId = EventLoop::getInstance()->addTimer($totalDelay, function () use ($promise, $networkConditions, &$isCancelled) {
                if ($isCancelled || $promise->isCancelled()) {
                    return;
                }
                $promise->reject(new HttpException($networkConditions['error_message'] ?? 'Request timeout'));
            });
            return;
        }

        if ($networkConditions['should_fail']) {
            $timerId = EventLoop::getInstance()->addTimer($totalDelay, function () use ($promise, $networkConditions, &$isCancelled) {
                if ($isCancelled || $promise->isCancelled()) {
                    return;
                }
                $promise->reject(new HttpException($networkConditions['error_message'] ?? 'Network failure'));
            });
            return;
        }

        $timerId = EventLoop::getInstance()->addTimer($totalDelay, function () use ($promise, $callback, &$isCancelled) {
            if ($isCancelled || $promise->isCancelled()) {
                return;
            }

            try {
                $result = $callback();
                $promise->resolve($result);
            } catch (Exception $e) {
                $promise->reject($e);
            }
        });
    }
}