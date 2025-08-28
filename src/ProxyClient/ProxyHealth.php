<?php 

namespace Rcalicdan\FiberAsync\ProxyClient;

/**
 * Enhanced proxy health tracking
 */
final readonly class ProxyHealth
{
    public function __construct(
        private int $attempts = 0,
        private int $successes = 0,
        private int $failures = 0,
        private int $consecutiveFailures = 0,
        private float $lastFailureTime = 0.0,
        private array $failureReasons = [],
        private float $avgResponseTime = 0.0,
        private int $responseTimeCount = 0
    ) {}

    public function recordSuccess(float $responseTime = 0.0): self
    {
        $newAvgResponseTime = $this->avgResponseTime;
        $newResponseTimeCount = $this->responseTimeCount;

        if ($responseTime > 0) {
            $newAvgResponseTime = (($this->avgResponseTime * $this->responseTimeCount) + $responseTime) / ($this->responseTimeCount + 1);
            $newResponseTimeCount = $this->responseTimeCount + 1;
        }

        return new self(
            attempts: $this->attempts + 1,
            successes: $this->successes + 1,
            failures: $this->failures,
            consecutiveFailures: 0,
            lastFailureTime: $this->lastFailureTime,
            failureReasons: $this->failureReasons,
            avgResponseTime: $newAvgResponseTime,
            responseTimeCount: $newResponseTimeCount
        );
    }

    public function recordFailure(string $reason): self
    {
        $failureReasons = $this->failureReasons;
        $failureReasons[$reason] = ($failureReasons[$reason] ?? 0) + 1;

        return new self(
            attempts: $this->attempts + 1,
            successes: $this->successes,
            failures: $this->failures + 1,
            consecutiveFailures: $this->consecutiveFailures + 1,
            lastFailureTime: microtime(true),
            failureReasons: $failureReasons,
            avgResponseTime: $this->avgResponseTime,
            responseTimeCount: $this->responseTimeCount
        );
    }

    public function getSuccessRate(): float
    {
        return $this->attempts > 0 ? ($this->successes / $this->attempts) : 0.0;
    }

    public function shouldRemove(int $maxConsecutiveFailures = 3, float $minSuccessRate = 0.2): bool
    {
        // Remove if too many consecutive failures
        if ($this->consecutiveFailures >= $maxConsecutiveFailures) {
            return true;
        }

        // Remove if success rate is too low (with minimum attempts)
        if ($this->attempts >= 5 && $this->getSuccessRate() < $minSuccessRate) {
            return true;
        }

        return false;
    }

    public function shouldQuarantine(float $quarantineDuration = 300.0): bool
    {
        // Quarantine recently failed proxies for a period
        return $this->consecutiveFailures > 0 &&
            (microtime(true) - $this->lastFailureTime) < $quarantineDuration;
    }

    public function getQualityScore(): float
    {
        if ($this->attempts === 0) {
            return 0.5;
        } // Neutral score for untested

        $successRate = $this->getSuccessRate();
        $recencyBonus = $this->consecutiveFailures === 0 ? 0.1 : 0;
        $speedBonus = $this->avgResponseTime > 0 && $this->avgResponseTime < 5.0 ? 0.1 : 0;

        return min(1.0, $successRate + $recencyBonus + $speedBonus);
    }

    // Getters
    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getSuccesses(): int
    {
        return $this->successes;
    }

    public function getFailures(): int
    {
        return $this->failures;
    }

    public function getConsecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }

    public function getFailureReasons(): array
    {
        return $this->failureReasons;
    }

    public function getAvgResponseTime(): float
    {
        return $this->avgResponseTime;
    }
}