<?php

namespace Rcalicdan\FiberAsync\ProxyClient;

/**
 * Enhanced immutable configuration for the resilient HTTP client
 */
final readonly class ResilientClientConfig
{
    public function __construct(
        private int $maxAttempts = 3,
        private int $timeoutSeconds = 30,
        private bool $trackResponseTime = true,
        private int $maxConsecutiveFailures = 3,
        private float $minSuccessRate = 0.2,
        private float $quarantineDuration = 300.0, // 5 minutes
        private string $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        private bool $enableHealthReporting = true
    ) {}

    // Getters
    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function getTimeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    public function shouldTrackResponseTime(): bool
    {
        return $this->trackResponseTime;
    }

    public function getMaxConsecutiveFailures(): int
    {
        return $this->maxConsecutiveFailures;
    }

    public function getMinSuccessRate(): float
    {
        return $this->minSuccessRate;
    }

    public function getQuarantineDuration(): float
    {
        return $this->quarantineDuration;
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    public function isHealthReportingEnabled(): bool
    {
        return $this->enableHealthReporting;
    }

    // Fluent setters
    public function withMaxAttempts(int $maxAttempts): self
    {
        return new self(
            $maxAttempts,
            $this->timeoutSeconds,
            $this->trackResponseTime,
            $this->maxConsecutiveFailures,
            $this->minSuccessRate,
            $this->quarantineDuration,
            $this->userAgent,
            $this->enableHealthReporting
        );
    }

    public function withTimeout(int $timeoutSeconds): self
    {
        return new self(
            $this->maxAttempts,
            $timeoutSeconds,
            $this->trackResponseTime,
            $this->maxConsecutiveFailures,
            $this->minSuccessRate,
            $this->quarantineDuration,
            $this->userAgent,
            $this->enableHealthReporting
        );
    }

    public function withHealthTracking(bool $trackResponseTime = true, bool $enableReporting = true): self
    {
        return new self(
            $this->maxAttempts,
            $this->timeoutSeconds,
            $trackResponseTime,
            $this->maxConsecutiveFailures,
            $this->minSuccessRate,
            $this->quarantineDuration,
            $this->userAgent,
            $enableReporting
        );
    }

    public function withProxyHealthPolicy(int $maxConsecutiveFailures = 3, float $minSuccessRate = 0.2, float $quarantineDuration = 300.0): self
    {
        return new self(
            $this->maxAttempts,
            $this->timeoutSeconds,
            $this->trackResponseTime,
            $maxConsecutiveFailures,
            $minSuccessRate,
            $quarantineDuration,
            $this->userAgent,
            $this->enableHealthReporting
        );
    }

    public function withUserAgent(string $userAgent): self
    {
        return new self(
            $this->maxAttempts,
            $this->timeoutSeconds,
            $this->trackResponseTime,
            $this->maxConsecutiveFailures,
            $this->minSuccessRate,
            $this->quarantineDuration,
            $userAgent,
            $this->enableHealthReporting
        );
    }
}
