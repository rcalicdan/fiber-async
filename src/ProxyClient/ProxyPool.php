<?php

namespace Rcalicdan\FiberAsync\ProxyClient;

/**
 * Enhanced immutable proxy pool with intelligent health tracking
 */
final readonly class ProxyPool
{
    public function __construct(
        private array $proxies,
        private array $healthMap = [],
        private array $quarantined = [],
        private array $removed = []
    ) {}

    public function isEmpty(): bool
    {
        return empty($this->getAvailableProxies());
    }

    public function getAvailableProxies(): array
    {
        $currentTime = microtime(true);
        $available = [];

        foreach ($this->proxies as $proxy) {
            // Skip permanently removed proxies
            if (in_array($proxy, $this->removed)) {
                continue;
            }

            // Skip quarantined proxies that are still in quarantine
            if (isset($this->quarantined[$proxy]) && $currentTime < $this->quarantined[$proxy]) {
                continue;
            }

            $available[] = $proxy;
        }

        return $available;
    }

    public function getRandomProxy(): ?string
    {
        $available = $this->getAvailableProxies();
        if (empty($available)) {
            return null;
        }

        // Weighted random selection based on quality score
        $weights = [];
        foreach ($available as $proxy) {
            $health = $this->healthMap[$proxy] ?? new ProxyHealth;
            $weights[$proxy] = max(0.1, $health->getQualityScore()); // Minimum weight to give failing proxies a chance
        }

        return $this->weightedRandomSelect($weights);
    }

    private function weightedRandomSelect(array $weights): ?string
    {
        if (empty($weights)) {
            return null;
        }

        $totalWeight = array_sum($weights);
        $random = mt_rand() / mt_getrandmax() * $totalWeight;

        $currentWeight = 0;
        foreach ($weights as $proxy => $weight) {
            $currentWeight += $weight;
            if ($random <= $currentWeight) {
                return $proxy;
            }
        }

        // Fallback to random selection
        return array_rand($weights);
    }

    public function getProxyCount(): int
    {
        return count($this->getAvailableProxies());
    }

    public function getAllProxies(): array
    {
        return $this->proxies;
    }

    public function recordSuccess(string $proxy, float $responseTime = 0.0): self
    {
        $newHealthMap = $this->healthMap;
        $health = $newHealthMap[$proxy] ?? new ProxyHealth;
        $newHealthMap[$proxy] = $health->recordSuccess($responseTime);

        // Remove from quarantine if successful
        $newQuarantined = $this->quarantined;
        unset($newQuarantined[$proxy]);

        return new self($this->proxies, $newHealthMap, $newQuarantined, $this->removed);
    }

    public function recordFailure(string $proxy, string $reason): self
    {
        $newHealthMap = $this->healthMap;
        $health = $newHealthMap[$proxy] ?? new ProxyHealth;
        $newHealth = $health->recordFailure($reason);
        $newHealthMap[$proxy] = $newHealth;

        $newQuarantined = $this->quarantined;
        $newRemoved = $this->removed;

        // Check if proxy should be permanently removed
        if ($newHealth->shouldRemove()) {
            $newRemoved[] = $proxy;
            echo "  🗑️ Permanently removed proxy {$proxy} (consecutive failures: {$newHealth->getConsecutiveFailures()}, success rate: ".
                round($newHealth->getSuccessRate() * 100, 1)."%)\n";
        } elseif ($newHealth->shouldQuarantine()) {
            // Quarantine for 5 minutes
            $newQuarantined[$proxy] = microtime(true) + 300;
            echo "  ⏰ Quarantined proxy {$proxy} for 5 minutes\n";
        }

        return new self($this->proxies, $newHealthMap, $newQuarantined, $newRemoved);
    }

    public function getHealthStats(): array
    {
        $stats = [];
        foreach ($this->proxies as $proxy) {
            if (in_array($proxy, $this->removed)) {
                continue;
            }

            $health = $this->healthMap[$proxy] ?? new ProxyHealth;
            $stats[$proxy] = [
                'attempts' => $health->getAttempts(),
                'successes' => $health->getSuccesses(),
                'failures' => $health->getFailures(),
                'success_rate' => round($health->getSuccessRate() * 100, 1),
                'consecutive_failures' => $health->getConsecutiveFailures(),
                'quality_score' => round($health->getQualityScore(), 2),
                'avg_response_time' => round($health->getAvgResponseTime(), 2),
                'failure_reasons' => $health->getFailureReasons(),
                'quarantined' => isset($this->quarantined[$proxy]) && microtime(true) < $this->quarantined[$proxy],
            ];
        }

        return $stats;
    }

    public function getPoolHealth(): array
    {
        $available = count($this->getAvailableProxies());
        $quarantined = count(array_filter($this->quarantined, fn ($until) => microtime(true) < $until));
        $removed = count($this->removed);

        return [
            'total_proxies' => count($this->proxies),
            'available' => $available,
            'quarantined' => $quarantined,
            'permanently_removed' => $removed,
            'health_percentage' => count($this->proxies) > 0 ? round(($available / count($this->proxies)) * 100, 1) : 0,
        ];
    }
}
