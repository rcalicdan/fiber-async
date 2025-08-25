<?php

namespace Rcalicdan\FiberAsync\Http\Traits;

use Rcalicdan\FiberAsync\Http\CacheConfig;
use Rcalicdan\FiberAsync\Http\RetryConfig;
use Symfony\Contracts\Cache\CacheInterface;

trait FetchOptionTrait
{
    /**
     * Normalizes fetch options from various formats to cURL options.
     *
     * @param  string  $url  The target URL.
     * @param  array<int|string, mixed>  $options  The options to normalize.
     * @return array<int, mixed> Normalized cURL options.
     */
    public function normalizeFetchOptions(string $url, array $options): array
    {
        $cleanOptions = array_filter($options, function ($key) {
            return ! in_array($key, [
                'stream',
                'on_chunk',
                'onChunk',
                'download',
                'save_to',
                'retry',
                'cache',
                'retry_config',
                'cache_config',
            ], true);
        }, ARRAY_FILTER_USE_KEY);

        if ($this->isCurlOptionsFormat($cleanOptions)) {
            /** @var array<int, mixed> */
            $curlOptions = array_filter($cleanOptions, fn ($key) => is_int($key), ARRAY_FILTER_USE_KEY);

            $curlOptions[CURLOPT_URL] = $url;

            if (! isset($curlOptions[CURLOPT_RETURNTRANSFER])) {
                $curlOptions[CURLOPT_RETURNTRANSFER] = true;
            }
            if (! isset($curlOptions[CURLOPT_HEADER])) {
                $curlOptions[CURLOPT_HEADER] = true;
            }
            if (! isset($curlOptions[CURLOPT_NOBODY])) {
                $curlOptions[CURLOPT_NOBODY] = false;
            }

            return $curlOptions;
        }

        /** @var array<int, mixed> $curlOptions */
        $curlOptions = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => false,
        ];

        if (isset($options['method']) && is_string($options['method'])) {
            $curlOptions[CURLOPT_CUSTOMREQUEST] = strtoupper($options['method']);
        }

        if (isset($options['headers']) && is_array($options['headers'])) {
            $headerStrings = [];
            foreach ($options['headers'] as $name => $value) {
                if (is_string($name) && (is_string($value) || is_scalar($value))) {
                    $headerStrings[] = "{$name}: {$value}";
                }
            }
            $curlOptions[CURLOPT_HTTPHEADER] = $headerStrings;
        }

        if (isset($options['http_version']) && is_string($options['http_version'])) {
            $curlOptions[CURLOPT_HTTP_VERSION] = match ($options['http_version']) {
                '2.0', '2' => CURL_HTTP_VERSION_2TLS,
                '3.0', '3' => defined('CURL_HTTP_VERSION_3')
                    ? CURL_HTTP_VERSION_3
                    : CURL_HTTP_VERSION_1_1,
                '1.0' => CURL_HTTP_VERSION_1_0,
                default => CURL_HTTP_VERSION_1_1,
            };
        }

        if (isset($options['protocol']) && is_string($options['protocol'])) {
            $curlOptions[CURLOPT_HTTP_VERSION] = match ($options['protocol']) {
                '2.0', '2' => CURL_HTTP_VERSION_2TLS,
                '3.0', '3' => defined('CURL_HTTP_VERSION_3')
                    ? CURL_HTTP_VERSION_3
                    : CURL_HTTP_VERSION_1_1,
                '1.0' => CURL_HTTP_VERSION_1_0,
                default => CURL_HTTP_VERSION_1_1,
            };
        }

        if (isset($options['body'])) {
            $curlOptions[CURLOPT_POSTFIELDS] = $options['body'];
        }

        if (isset($options['json']) && is_array($options['json'])) {
            $curlOptions[CURLOPT_POSTFIELDS] = json_encode($options['json']);
            $headers = [];
            if (isset($curlOptions[CURLOPT_HTTPHEADER]) && is_array($curlOptions[CURLOPT_HTTPHEADER])) {
                $headers = $curlOptions[CURLOPT_HTTPHEADER];
            }
            $headers[] = 'Content-Type: application/json';
            $curlOptions[CURLOPT_HTTPHEADER] = $headers;
        }

        if (isset($options['form']) && is_array($options['form'])) {
            $curlOptions[CURLOPT_POSTFIELDS] = http_build_query($options['form']);
            $headers = [];
            if (isset($curlOptions[CURLOPT_HTTPHEADER]) && is_array($curlOptions[CURLOPT_HTTPHEADER])) {
                $headers = $curlOptions[CURLOPT_HTTPHEADER];
            }
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $curlOptions[CURLOPT_HTTPHEADER] = $headers;
        }

        if (isset($options['timeout']) && is_numeric($options['timeout'])) {
            $curlOptions[CURLOPT_TIMEOUT] = (int) $options['timeout'];
        }

        if (isset($options['connect_timeout']) && is_numeric($options['connect_timeout'])) {
            $curlOptions[CURLOPT_CONNECTTIMEOUT] = (int) $options['connect_timeout'];
        }

        if (isset($options['follow_redirects'])) {
            $curlOptions[CURLOPT_FOLLOWLOCATION] = (bool) $options['follow_redirects'];
        }

        if (isset($options['max_redirects']) && is_numeric($options['max_redirects'])) {
            $curlOptions[CURLOPT_MAXREDIRS] = (int) $options['max_redirects'];
        }

        if (isset($options['verify_ssl'])) {
            $verifySSL = (bool) $options['verify_ssl'];
            $curlOptions[CURLOPT_SSL_VERIFYPEER] = $verifySSL;
            $curlOptions[CURLOPT_SSL_VERIFYHOST] = $verifySSL ? 2 : 0;
        }

        if (isset($options['user_agent']) && is_string($options['user_agent'])) {
            $curlOptions[CURLOPT_USERAGENT] = $options['user_agent'];
        }

        if (isset($options['auth']) && is_array($options['auth'])) {
            $auth = $options['auth'];

            if (isset($auth['bearer']) && is_string($auth['bearer'])) {
                $headers = [];
                if (isset($curlOptions[CURLOPT_HTTPHEADER]) && is_array($curlOptions[CURLOPT_HTTPHEADER])) {
                    $headers = $curlOptions[CURLOPT_HTTPHEADER];
                }
                $headers[] = 'Authorization: Bearer '.$auth['bearer'];
                $curlOptions[CURLOPT_HTTPHEADER] = $headers;
            }

            if (isset($auth['basic']) && is_array($auth['basic'])) {
                $basic = $auth['basic'];
                if (
                    isset($basic['username'], $basic['password']) &&
                    is_string($basic['username']) && is_string($basic['password'])
                ) {
                    $curlOptions[CURLOPT_USERPWD] = $basic['username'].':'.$basic['password'];
                    $curlOptions[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
                }
            }
        }

        return $curlOptions;
    }

    private function isCurlOptionsFormat(array $options): bool
    {
        foreach (array_keys($options) as $key) {
            if (is_int($key) && $key > 0) {
                return true;
            }
        }

        return false;
    }

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
