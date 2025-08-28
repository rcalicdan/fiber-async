<?php

namespace Rcalicdan\FiberAsync\Http\SSE;

use Exception;
use Rcalicdan\FiberAsync\Promise\Interfaces\CancellablePromiseInterface;

/**
 * Manages the state of an SSE connection including reconnection attempts.
 */
class SSEConnectionState
{
    private int $attemptCount = 0;
    private ?string $lastEventId = null;
    private ?int $retryInterval = null;
    private bool $cancelled = false;
    private ?CancellablePromiseInterface $currentConnection = null;
    private ?Exception $lastError = null;

    public function __construct(
        private readonly string $url,
        private readonly array $options,
        private readonly SSEReconnectConfig $config
    ) {}

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function getConfig(): SSEReconnectConfig
    {
        return $this->config;
    }

    public function incrementAttempt(): void
    {
        $this->attemptCount++;
    }

    public function getAttemptCount(): int
    {
        return $this->attemptCount;
    }

    public function setLastEventId(?string $eventId): void
    {
        $this->lastEventId = $eventId;
    }

    public function getLastEventId(): ?string
    {
        return $this->lastEventId;
    }

    public function setRetryInterval(?int $interval): void
    {
        $this->retryInterval = $interval;
    }

    public function getRetryInterval(): ?int
    {
        return $this->retryInterval;
    }

    public function cancel(): void
    {
        $this->cancelled = true;
        if ($this->currentConnection !== null) {
            $this->currentConnection->cancel();
        }
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    public function setCurrentConnection(CancellablePromiseInterface $connection): void
    {
        $this->currentConnection = $connection;
    }

    public function onConnected(): void
    {
        $this->attemptCount = 0;
        $this->lastError = null;
    }

    public function onConnectionFailed(Exception $error): void
    {
        $this->lastError = $error;
    }

    public function shouldReconnect(?Exception $error = null): bool
    {
        if (!$this->config->enabled) {
            return false;
        }

        if ($this->cancelled) {
            return false;
        }

        if ($this->attemptCount >= $this->config->maxAttempts) {
            return false;
        }

        $errorToCheck = $error ?? $this->lastError;
        if ($errorToCheck !== null) {
            return $this->config->isRetryableError($errorToCheck);
        }

        return true;
    }

    public function getReconnectDelay(): float
    {
        // Use server-suggested retry interval if available
        if ($this->retryInterval !== null) {
            return $this->retryInterval / 1000.0; // Convert from milliseconds
        }

        return $this->config->calculateDelay($this->attemptCount);
    }
}