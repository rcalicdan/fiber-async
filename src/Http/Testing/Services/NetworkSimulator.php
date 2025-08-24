<?php

namespace Rcalicdan\FiberAsync\Http\Testing\Services;

class NetworkSimulator
{
    private bool $enabled = false;
    private array $settings = [
        'failure_rate' => 0.0,
        'timeout_rate' => 0.0,
        'connection_failure_rate' => 0.0,
        'default_delay' => 0,
        'timeout_delay' => 30.0,
        'retryable_failure_rate' => 0.0, 
    ];

    public function enable(array $settings = []): void
    {
        $this->enabled = true;
        $this->settings = array_merge($this->settings, $settings);
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    /**
     * Simulates network conditions and may throw exceptions or modify behavior
     *
     * @return array{should_timeout: bool, should_fail: bool, error_message: string|null, delay: float}
     */
    public function simulate(): array
    {
        if (! $this->enabled) {
            return [
                'should_timeout' => false,
                'should_fail' => false,
                'error_message' => null,
                'delay' => $this->calculateDelay($this->settings['default_delay']),
            ];
        }

        $result = [
            'should_timeout' => false,
            'should_fail' => false,
            'error_message' => null,
            'delay' => $this->calculateDelay($this->settings['default_delay']),
        ];

        if (mt_rand() / mt_getrandmax() < $this->settings['timeout_rate']) {
            $result['should_timeout'] = true;
            $result['delay'] = $this->calculateDelay($this->settings['timeout_delay']);
            $result['error_message'] = sprintf(
                'Connection timed out after %.1fs (simulated network timeout)',
                $result['delay']
            );

            return $result;
        }

        if (mt_rand() / mt_getrandmax() < $this->settings['retryable_failure_rate']) {
            $result['should_fail'] = true;
            $retryableErrors = [
                'Connection failed (network simulation)',
                'Could not resolve host (network simulation)',
                'Connection timed out during handshake (network simulation)',
                'SSL connection timeout (network simulation)',
                'Resolving timed out (network simulation)',
            ];
            $result['error_message'] = $retryableErrors[array_rand($retryableErrors)];

            return $result;
        }

        if (mt_rand() / mt_getrandmax() < $this->settings['failure_rate']) {
            $result['should_fail'] = true;
            $result['error_message'] = 'Simulated network failure';

            return $result;
        }

        if (mt_rand() / mt_getrandmax() < $this->settings['connection_failure_rate']) {
            $result['should_fail'] = true;
            $result['error_message'] = 'Connection refused (network simulation)';

            return $result;
        }

        return $result;
    }

    /**
     * Calculate delay based on configuration (supports both single values and arrays)
     *
     * @param mixed $delayConfig
     * @return float
     */
    private function calculateDelay($delayConfig): float
    {
        if (is_array($delayConfig)) {
            if (count($delayConfig) === 2 && is_numeric($delayConfig[0]) && is_numeric($delayConfig[1])) {
                $min = (float) $delayConfig[0];
                $max = (float) $delayConfig[1];
                return $min + (mt_rand() / mt_getrandmax()) * ($max - $min);
            } elseif (count($delayConfig) > 0) {
                $randomKey = array_rand($delayConfig);
                return (float) $delayConfig[$randomKey];
            }
            
            return 0.0;
        }

        return (float) $delayConfig;
    }

    public function getDefaultDelay(): float
    {
        return $this->calculateDelay($this->settings['default_delay']);
    }

    public function getTimeoutDelay(): float
    {
        return $this->calculateDelay($this->settings['timeout_delay']);
    }
}