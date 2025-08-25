<?php

namespace Rcalicdan\FiberAsync\Http\Traits;

use Rcalicdan\FiberAsync\Http\CacheConfig;
use Rcalicdan\FiberAsync\Http\RetryConfig;
use Symfony\Contracts\Cache\CacheInterface;

trait FetchOptionTrait
{
    private function extractCacheConfig(array $options): ?CacheConfig
    {
        if (! isset($options['cache'])) {
            return null;
        }

        $cache = $options['cache'];

        if ($cache === true) {
            return new CacheConfig;
        }

        // Handle CacheConfig object
        if ($cache instanceof CacheConfig) {
            return $cache;
        }

        // Handle array configuration
        if (is_array($cache)) {
            $cacheInstance = null;
            if (isset($cache['cache_instance']) && $cache['cache_instance'] instanceof CacheInterface) {
                $cacheInstance = $cache['cache_instance'];
            }

            $ttl = $cache['ttl'] ?? 3600;

            return new CacheConfig(
                ttlSeconds: is_numeric($ttl) ? (int) $ttl : 3600,
                respectServerHeaders: (bool) ($cache['respect_server_headers'] ?? true),
                cache: $cacheInstance
            );
        }

        // Handle integer as TTL
        if (is_int($cache)) {
            return new CacheConfig($cache);
        }

        return null;
    }

    /**
     * Extracts retry configuration from options array.
     *
     * @param  array<int|string, mixed>  $options
     */
    private function extractRetryConfig(array $options): ?RetryConfig
    {
        if (! isset($options['retry'])) {
            return null;
        }

        $retry = $options['retry'];

        if ($retry === true) {
            return new RetryConfig;
        }

        if ($retry instanceof RetryConfig) {
            return $retry;
        }

        if (is_array($retry)) {
            $retryableStatusCodes = [408, 429, 500, 502, 503, 504];
            if (isset($retry['retryable_status_codes']) && is_array($retry['retryable_status_codes'])) {
                $codes = [];
                foreach ($retry['retryable_status_codes'] as $code) {
                    if (is_numeric($code)) {
                        $codes[] = (int) $code;
                    }
                }
                $retryableStatusCodes = $codes;
            }

            $retryableExceptions = [
                'cURL error',
                'timeout',
                'connection failed',
                'Could not resolve host',
                'Resolving timed out',
                'Connection timed out',
                'SSL connection timeout',
            ];
            if (isset($retry['retryable_exceptions']) && is_array($retry['retryable_exceptions'])) {
                $exceptions = [];
                foreach ($retry['retryable_exceptions'] as $exception) {
                    if (is_scalar($exception)) {
                        $exceptions[] = (string) $exception;
                    }
                }
                $retryableExceptions = $exceptions;
            }

            $maxRetries = $retry['max_retries'] ?? 3;
            $baseDelay = $retry['base_delay'] ?? 1.0;
            $maxDelay = $retry['max_delay'] ?? 60.0;
            $backoffMultiplier = $retry['backoff_multiplier'] ?? 2.0;

            return new RetryConfig(
                maxRetries: is_numeric($maxRetries) ? (int) $maxRetries : 3,
                baseDelay: is_numeric($baseDelay) ? (float) $baseDelay : 1.0,
                maxDelay: is_numeric($maxDelay) ? (float) $maxDelay : 60.0,
                backoffMultiplier: is_numeric($backoffMultiplier) ? (float) $backoffMultiplier : 2.0,
                jitter: (bool) ($retry['jitter'] ?? true),
                retryableStatusCodes: $retryableStatusCodes,
                retryableExceptions: $retryableExceptions
            );
        }

        return null;
    }
}
