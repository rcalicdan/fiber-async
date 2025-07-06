<?php

namespace Rcalicdan\FiberAsync\Http;

class RetryConfig
{
    public function __construct(
        public readonly int $maxRetries = 3,
        public readonly float $baseDelay = 1.0,
        public readonly float $maxDelay = 60.0,
        public readonly float $backoffMultiplier = 2.0,
        public readonly bool $jitter = true,
        public readonly array $retryableStatusCodes = [408, 429, 500, 502, 503, 504],
        public readonly array $retryableExceptions = ['cURL error', 'timeout', 'connection failed']
    ) {}

    public function shouldRetry(int $attempt, int $statusCode = 0, ?string $error = null): bool
    {
        if ($attempt >= $this->maxRetries) {
            return false;
        }

        // Retry on retryable status codes
        if ($statusCode > 0 && in_array($statusCode, $this->retryableStatusCodes)) {
            return true;
        }

        // Retry on retryable exceptions
        if ($error && $this->isRetryableError($error)) {
            return true;
        }

        return false;
    }

    public function getDelay(int $attempt): float
    {
        $delay = $this->baseDelay * pow($this->backoffMultiplier, $attempt - 1);
        $delay = min($delay, $this->maxDelay);

        if ($this->jitter) {
            // Add random jitter (±25% of the delay)
            $jitterRange = $delay * 0.25;
            $delay += mt_rand(-$jitterRange * 100, $jitterRange * 100) / 100;
        }

        return max(0, $delay);
    }

    private function isRetryableError(string $error): bool
    {
        foreach ($this->retryableExceptions as $retryableError) {
            if (stripos($error, $retryableError) !== false) {
                return true;
            }
        }

        return false;
    }
}
