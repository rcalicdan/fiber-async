<?php

namespace Rcalicdan\FiberAsync\Http\Testing\Services;

use Rcalicdan\FiberAsync\Http\Exceptions\HttpException;

class NetworkSimulator
{
    private bool $enabled = false;
    private array $settings = [
        'failure_rate' => 0.0,
        'timeout_rate' => 0.0,
        'default_delay' => 0,
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

    public function simulate(): void
    {
        if (!$this->enabled) {
            return;
        }

        if (mt_rand() / mt_getrandmax() < $this->settings['failure_rate']) {
            throw new HttpException("Simulated network failure");
        }

        if (mt_rand() / mt_getrandmax() < $this->settings['timeout_rate']) {
            throw new HttpException("Simulated timeout");
        }
    }

    public function getDefaultDelay(): float
    {
        return $this->settings['default_delay'];
    }
}