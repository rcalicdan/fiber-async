<?php

namespace Rcalicdan\FiberAsync\Http\Testing\Services;

use Exception;
use Rcalicdan\FiberAsync\EventLoop\EventLoop;
use Rcalicdan\FiberAsync\Http\Exceptions\HttpException;
use Rcalicdan\FiberAsync\Http\Response;
use Rcalicdan\FiberAsync\Http\RetryConfig;
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

    /**
     * Create a mocked response with retry logic support
     */
    public function createRetryableMockedResponse(MockedRequest $mock, RetryConfig $retryConfig, ?callable $onAttempt = null): PromiseInterface
    {
        /** @var CancellablePromise<Response> $promise */
        $promise = new CancellablePromise();
        $attempt = 0;
        $totalAttempts = 0;
        $timeoutAttempts = 0;
        $failureAttempts = 0;
        /** @var string|null $timerId */
        $timerId = null;
        $isCancelled = false;
        $failureHistory = [];

        $promise->setCancelHandler(function () use (&$timerId, &$isCancelled) {
            $isCancelled = true;
            if ($timerId !== null) {
                EventLoop::getInstance()->cancelTimer($timerId);
            }
        });

        $executeAttempt = function () use ($mock, $retryConfig, $promise, &$attempt, &$totalAttempts, &$timeoutAttempts, &$failureAttempts, &$timerId, &$isCancelled, &$failureHistory, $onAttempt, &$executeAttempt) {
            if ($isCancelled || $promise->isCancelled()) {
                return;
            }

            $totalAttempts++;
            if ($onAttempt) {
                $onAttempt();
            }

            // Simulate network conditions
            $networkConditions = $this->networkSimulator->simulate();
            $mockShouldFail = $mock->shouldFail();
            $mockIsTimeout = $mock->isTimeout();
            $mockIsRetryable = $mock->isRetryableFailure();

            // Determine failure type and track it
            $failureType = null;
            $shouldFail = false;
            $errorMessage = '';
            $isRetryable = false;

            if ($networkConditions['should_timeout']) {
                $shouldFail = true;
                $failureType = 'network_timeout';
                $timeoutAttempts++;
                $errorMessage = sprintf(
                    'Connection timed out after %.1fs (network simulation)',
                    $networkConditions['delay'] ?? $this->networkSimulator->getTimeoutDelay()
                );
                $isRetryable = $this->isNetworkErrorRetryable($errorMessage, $retryConfig);
            } elseif ($networkConditions['should_fail']) {
                $shouldFail = true;
                $failureType = 'network_failure';
                $failureAttempts++;
                $errorMessage = $networkConditions['error_message'] ?? 'Network failure';
                $isRetryable = $this->isNetworkErrorRetryable($errorMessage, $retryConfig);
            } elseif ($mockIsTimeout) {
                $shouldFail = true;
                $failureType = 'mock_timeout';
                $timeoutAttempts++;
                $timeoutSeconds = $mock->getDelay();
                $errorMessage = sprintf(
                    'Mock timeout after %.1fs (configured mock timeout)',
                    $timeoutSeconds
                );
                $isRetryable = $mockIsRetryable || $this->isMockErrorRetryable($errorMessage, $retryConfig);
            } elseif ($mockShouldFail) {
                $shouldFail = true;
                $failureType = 'mock_failure';
                $failureAttempts++;
                $errorMessage = $mock->getError() ?? 'Mock failure';
                $isRetryable = $mockIsRetryable || $this->isMockErrorRetryable($errorMessage, $retryConfig);
            }

            // Track failure history
            if ($shouldFail) {
                $failureHistory[] = [
                    'attempt' => $totalAttempts,
                    'type' => $failureType,
                    'message' => $errorMessage,
                    'retryable' => $isRetryable,
                    'timestamp' => microtime(true)
                ];
            }

            // Calculate delay (use the larger of network simulation or mock delay)
            $delay = max(
                $networkConditions['delay'] ?? 0,
                $mock->getDelay()
            );

            if ($shouldFail && $isRetryable && $attempt < $retryConfig->maxRetries) {
                $attempt++;
                $retryDelay = $delay + $retryConfig->getDelay($attempt);

                $timerId = EventLoop::getInstance()->addTimer($retryDelay, $executeAttempt);
                return;
            }

            // Final result (success or failure)
            $timerId = EventLoop::getInstance()->addTimer($delay, function () use ($shouldFail, $errorMessage, $mock, $promise, $totalAttempts, $timeoutAttempts, $failureAttempts, $failureHistory, &$isCancelled) {
                if ($isCancelled || $promise->isCancelled()) {
                    return;
                }

                if ($shouldFail) {
                    // Create detailed error message with failure breakdown
                    $detailedError = $this->createDetailedErrorMessage(
                        $errorMessage,
                        $totalAttempts,
                        $timeoutAttempts,
                        $failureAttempts,
                        $failureHistory
                    );

                    $promise->reject(new HttpException($detailedError));
                } else {
                    $response = new Response(
                        $mock->getBody(),
                        $mock->getStatusCode(),
                        $mock->getHeaders()
                    );
                    $promise->resolve($response);
                }
            });
        };

        // Start the first attempt
        $executeAttempt();

        return $promise;
    }

    /**
     * Create a detailed error message with failure breakdown
     */
    private function createDetailedErrorMessage(string $finalError, int $totalAttempts, int $timeoutAttempts, int $failureAttempts, array $failureHistory): string
    {
        $message = "HTTP Request failed after {$totalAttempts} attempt" . ($totalAttempts > 1 ? 's' : '') . ": {$finalError}";

        if ($totalAttempts > 1) {
            $breakdown = [];

            if ($timeoutAttempts > 0) {
                $breakdown[] = "{$timeoutAttempts} timeout" . ($timeoutAttempts > 1 ? 's' : '');
            }

            if ($failureAttempts > 0) {
                $breakdown[] = "{$failureAttempts} failure" . ($failureAttempts > 1 ? 's' : '');
            }

            if (!empty($breakdown)) {
                $message .= sprintf(" (Breakdown: %s)", implode(', ', $breakdown));
            }

            // Add detailed failure history if there were multiple attempts
            if (count($failureHistory) > 1) {
                $message .= "\nFailure history:";
                foreach ($failureHistory as $i => $failure) {
                    $message .= sprintf(
                        "\n  Attempt %d: %s (%s)",
                        $failure['attempt'],
                        $failure['message'],
                        $failure['type']
                    );
                }
            }
        }

        return $message;
    }

    /**
     * Check if a network error is retryable based on RetryConfig
     */
    private function isNetworkErrorRetryable(string $error, RetryConfig $retryConfig): bool
    {
        foreach ($retryConfig->retryableExceptions as $retryablePattern) {
            if (stripos($error, $retryablePattern) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a mock error is retryable based on RetryConfig
     */
    private function isMockErrorRetryable(string $error, RetryConfig $retryConfig): bool
    {
        foreach ($retryConfig->retryableExceptions as $retryablePattern) {
            if (stripos($error, $retryablePattern) !== false) {
                return true;
            }
        }
        return false;
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
