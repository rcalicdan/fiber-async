<?php

namespace Rcalicdan\FiberAsync\ProxyClient;

use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Http\Exceptions\HttpException;
use Rcalicdan\FiberAsync\Http\ProxyConfig;
use Rcalicdan\FiberAsync\Http\Request;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

/**
 * Enhanced auto-resilient HTTP client with intelligent proxy health management
 */
final class ProxyClient
{
    private static ?PromiseInterface $initializationPromise = null;
    private static ?ProxyPool $proxyPool = null;
    private static ProxyScraper $scraper;
    private static ResilientClientConfig $config;

    private static function initializeDefaults(): void
    {
        if (! isset(self::$scraper)) {
            self::$scraper = new ProxyScraper;
        }
        if (! isset(self::$config)) {
            self::$config = new ResilientClientConfig;
        }
    }

    private static function initialize(): PromiseInterface
    {
        if (self::$initializationPromise === null) {
            self::initializeDefaults();
            self::$initializationPromise = async(function () {
                echo "Initializing ResilientHttpClient: Populating proxy pool...\n";
                $proxies = await(self::$scraper->scrape());
                self::$proxyPool = new ProxyPool($proxies);

                $count = self::$proxyPool->getProxyCount();
                if ($count === 0) {
                    echo "Warning: Proxy pool is empty after scraping.\n";
                } else {
                    echo "Initialization complete. Proxy pool populated with {$count} proxies.\n";
                }

                return $count;
            });
        }

        return self::$initializationPromise;
    }

    /**
     * Makes a request with intelligent proxy selection and health tracking
     */
    private static function makeResilientRequest(string $method, string $url, array $options = []): PromiseInterface
    {
        return async(function () use ($method, $url, $options) {
            await(self::initialize());

            if (self::$proxyPool->isEmpty()) {
                throw new HttpException('No available proxies. All proxies may be quarantined or removed.');
            }

            $currentPool = self::$proxyPool;
            $config = self::$config;
            $attemptCount = 0;
            $maxAttempts = $config->getMaxAttempts();

            while ($attemptCount < $maxAttempts) {
                $proxy = $currentPool->getRandomProxy();
                if ($proxy === null) {
                    throw new HttpException('No available proxies for request.');
                }

                $attemptCount++;
                $requestId = uniqid();
                echo "  -> [{$requestId}] Attempt #{$attemptCount}/{$maxAttempts} with proxy: {$proxy}\n";

                $startTime = microtime(true);

                try {
                    $response = await(self::executeRequest($method, $url, $proxy, $options, $config));

                    // Calculate response time if tracking is enabled
                    $responseTime = $config->shouldTrackResponseTime() ? microtime(true) - $startTime : 0.0;

                    // Record success
                    $currentPool = $currentPool->recordSuccess($proxy, $responseTime);
                    self::updateProxyPool($currentPool);

                    echo "  -> [{$requestId}] ✅ SUCCESS with proxy: {$proxy}".
                        ($responseTime > 0 ? ' ('.round($responseTime, 2).'s)' : '')."\n";

                    return $response;
                } catch (\Throwable $e) {
                    // Categorize the failure reason
                    $failureReason = self::categorizeFailure($e);

                    // Record failure with specific reason
                    $currentPool = $currentPool->recordFailure($proxy, $failureReason);

                    echo "  -> [{$requestId}] ❌ FAILED with proxy {$proxy}: {$failureReason}\n";

                    if ($attemptCount >= $maxAttempts) {
                        self::updateProxyPool($currentPool);

                        throw new HttpException('All attempts failed. Last error: '.$e->getMessage(), 0, $e);
                    }
                    echo "  -> [{$requestId}] 🔄 Trying with another proxy...\n";
                }
            }

            self::updateProxyPool($currentPool);

            throw new HttpException('Exhausted all retry attempts.');
        });
    }

    private static function categorizeFailure(\Throwable $e): string
    {
        $message = strtolower($e->getMessage());

        if (str_contains($message, 'timeout') || str_contains($message, 'timed out')) {
            return 'timeout';
        }
        if (str_contains($message, 'connection refused') || str_contains($message, 'failed to connect')) {
            return 'connection_refused';
        }
        if (str_contains($message, 'ssl') || str_contains($message, 'certificate')) {
            return 'ssl_error';
        }
        if (str_contains($message, '407') || str_contains($message, 'proxy authentication')) {
            return 'proxy_auth_required';
        }
        if (str_contains($message, '502') || str_contains($message, 'bad gateway')) {
            return 'bad_gateway';
        }
        if (str_contains($message, 'proxy connect')) {
            return 'proxy_connect_failed';
        }

        return 'unknown_error';
    }

    private static function executeRequest(string $method, string $url, string $proxy, array $options, ResilientClientConfig $config): PromiseInterface
    {
        return async(function () use ($method, $url, $proxy, $options, $config) {
            $requestBuilder = Http::request()
                ->timeout($config->getTimeoutSeconds())
                ->userAgent($config->getUserAgent())
            ;

            if (isset($options['json'])) {
                $requestBuilder->json($options['json']);
            }
            if (isset($options['form'])) {
                $requestBuilder->form($options['form']);
            }
            if (isset($options['headers'])) {
                foreach ($options['headers'] as $name => $value) {
                    $requestBuilder->header($name, $value);
                }
            }

            return await($requestBuilder
                ->interceptRequest(function (Request $request) use ($proxy): Request {
                    return $request->proxyWith(new ProxyConfig(
                        host: parse_url('http://'.$proxy, PHP_URL_HOST),
                        port: (int) parse_url('http://'.$proxy, PHP_URL_PORT)
                    ));
                })
                ->send($method, $url));
        });
    }

    private static function updateProxyPool(ProxyPool $newPool): void
    {
        self::$proxyPool = $newPool;
    }

    // ======================================================================
    // PUBLIC STATIC API
    // ======================================================================

    public static function configure(ResilientClientConfig $config): void
    {
        self::$config = $config;
    }

    public static function get(string $url, array $headers = []): PromiseInterface
    {
        return self::makeResilientRequest('GET', $url, ['headers' => $headers]);
    }

    public static function post(string $url, array $data, array $headers = []): PromiseInterface
    {
        return self::makeResilientRequest('POST', $url, ['json' => $data, 'headers' => $headers]);
    }

    public static function put(string $url, array $data, array $headers = []): PromiseInterface
    {
        return self::makeResilientRequest('PUT', $url, ['json' => $data, 'headers' => $headers]);
    }

    public static function patch(string $url, array $data, array $headers = []): PromiseInterface
    {
        return self::makeResilientRequest('PATCH', $url, ['json' => $data, 'headers' => $headers]);
    }

    public static function delete(string $url, array $headers = []): PromiseInterface
    {
        return self::makeResilientRequest('DELETE', $url, ['headers' => $headers]);
    }

    public static function postForm(string $url, array $formData, array $headers = []): PromiseInterface
    {
        return self::makeResilientRequest('POST', $url, ['form' => $formData, 'headers' => $headers]);
    }

    public static function getProxyHealthStats(): array
    {
        return self::$proxyPool?->getHealthStats() ?? [];
    }

    public static function getPoolHealth(): array
    {
        return self::$proxyPool?->getPoolHealth() ?? [
            'total_proxies' => 0,
            'available' => 0,
            'quarantined' => 0,
            'permanently_removed' => 0,
            'health_percentage' => 0,
        ];
    }

    public static function getProxyCount(): int
    {
        return self::$proxyPool?->getProxyCount() ?? 0;
    }

    /**
     * Refresh the proxy pool by scraping again
     */
    public static function refreshProxies(): PromiseInterface
    {
        self::initializeDefaults();

        return async(function () {
            echo "Refreshing proxy pool...\n";
            $proxies = await(self::$scraper->scrape());
            self::$proxyPool = new ProxyPool($proxies);
            $count = self::$proxyPool->getProxyCount();
            echo "Proxy pool refreshed with {$count} proxies.\n";

            return $count;
        });
    }

    /**
     * Force release quarantined proxies (for testing/emergency)
     */
    public static function releaseQuarantinedProxies(): void
    {
        if (self::$proxyPool) {
            self::$proxyPool = new ProxyPool(
                self::$proxyPool->getAllProxies(),
                self::getProxyHealthStats()
            );
            echo "All quarantined proxies have been released.\n";
        }
    }
}
