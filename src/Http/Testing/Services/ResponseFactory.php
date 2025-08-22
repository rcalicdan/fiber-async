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

        EventLoop::getInstance()->addTimer($mock->getDelay(), function () use ($promise, $mock) {
            try {
                $this->networkSimulator->simulate();

                if ($mock->shouldFail()) {
                    $promise->reject(new HttpException($mock->getError()));
                } else {
                    $response = new Response(
                        $mock->getBody(),
                        $mock->getStatusCode(),
                        $mock->getHeaders()
                    );
                    $promise->resolve($response);
                }
            } catch (Exception $e) {
                $promise->reject($e);
            }
        });

        return $promise;
    }

    public function createMockedStream(MockedRequest $mock, ?callable $onChunk, callable $createStream): CancellablePromiseInterface
    {
        /** @var CancellablePromise<StreamingResponse> $promise */
        $promise = new CancellablePromise();

        EventLoop::getInstance()->addTimer($mock->getDelay(), function () use ($promise, $mock, $onChunk, $createStream) {
            try {
                $this->networkSimulator->simulate();

                if ($mock->shouldFail()) {
                    $promise->reject(new HttpException($mock->getError()));
                } else {
                    if ($onChunk !== null) {
                        $body = $mock->getBody();
                        $chunkSize = 1024;
                        for ($i = 0; $i < strlen($body); $i += $chunkSize) {
                            $chunk = substr($body, $i, $chunkSize);
                            $onChunk($chunk);
                        }
                    }

                    $stream = $createStream($mock->getBody());
                    $streamingResponse = new StreamingResponse(
                        $stream,
                        $mock->getStatusCode(),
                        $mock->getHeaders()
                    );

                    $promise->resolve($streamingResponse);
                }
            } catch (Exception $e) {
                $promise->reject($e);
            }
        });

        return $promise;
    }

    public function createMockedDownload(MockedRequest $mock, string $destination, FileManager $fileManager): CancellablePromiseInterface
    {
        /** @var CancellablePromise<array> $promise */
        $promise = new CancellablePromise();

        EventLoop::getInstance()->addTimer($mock->getDelay(), function () use ($promise, $mock, $destination, $fileManager) {
            try {
                $this->networkSimulator->simulate();

                if ($mock->shouldFail()) {
                    $promise->reject(new Exception($mock->getError()));
                } else {
                    $directory = dirname($destination);
                    if (!is_dir($directory)) {
                        if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
                            $promise->reject(new Exception("Cannot create directory: {$directory}"));
                            return;
                        }
                        $fileManager->trackDirectory($directory);
                    }

                    $bytesWritten = file_put_contents($destination, $mock->getBody());
                    if ($bytesWritten === false) {
                        $promise->reject(new Exception("Cannot write to file: {$destination}"));
                        return;
                    }

                    $fileManager->trackFile($destination);

                    $promise->resolve([
                        'file' => $destination,
                        'status' => $mock->getStatusCode(),
                        'headers' => $mock->getHeaders(),
                        'size' => strlen($mock->getBody()),
                        'protocol_version' => '2.0'
                    ]);
                }
            } catch (Exception $e) {
                $promise->reject($e);
            }
        });

        return $promise;
    }
}