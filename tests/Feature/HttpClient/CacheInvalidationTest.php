<?php

use Psr\SimpleCache\CacheInterface;
use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Http\CacheConfig;
use Rcalicdan\FiberAsync\Http\Handlers\HttpHandler;
use Rcalicdan\FiberAsync\Http\Response;

/**
 * A test-only, in-memory PSR-16 cache that tracks all operations.
 */
class TrackableCacheTest implements CacheInterface
{
    private array $storage = [];
    private array $operations = [];

    public function get(string $key, mixed $default = null): mixed
    {
        $this->operations[] = ['get', $key];

        return $this->storage[$key] ?? $default;
    }

    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        $this->operations[] = ['set', $key, $value, $ttl];
        $this->storage[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        $this->operations[] = ['delete', $key];
        unset($this->storage[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->operations[] = ['clear'];
        $this->storage = [];

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        return [];
    }

    public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
    {
        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return true;
    }

    public function has(string $key): bool
    {
        return isset($this->storage[$key]);
    }

    public function getOperationsCount(string $type): int
    {
        return count(array_filter($this->operations, fn ($op) => $op[0] === $type));
    }

    public function getLastOperation(string $type): ?array
    {
        $ops = array_values(array_filter($this->operations, fn ($op) => $op[0] === $type));

        return empty($ops) ? null : end($ops);
    }
}

describe('HTTP Client Cache Invalidation', function () {

    test('invalidates cache for a GET request after a successful PUT request', function () {
        $trackableCache = new TrackableCacheTest;
        $cacheConfig = new CacheConfig(cache: $trackableCache);
        $url = 'https://jsonplaceholder.typicode.com/posts/1'; // Use a REAL endpoint here

        run(function () use ($trackableCache, $cacheConfig, $url) {
            // 1. First GET: Populates the cache.
            await(http()->cacheWith($cacheConfig)->get($url));
            expect($trackableCache->getOperationsCount('set'))->toBe(1);

            // 2. Second GET: Hits the cache.
            await(http()->cacheWith($cacheConfig)->get($url));
            expect($trackableCache->getOperationsCount('set'))->toBe(1); // No change

            // 3. PUT Request (The "Mutation Event").
            $updateResponse = await(http()->put($url, ['title' => 'new title']));
            expect($updateResponse->ok())->toBeTrue();

            // 4. Manual Invalidation (The Application Logic).
            $cacheKey = HttpHandler::generateCacheKey($url);
            $trackableCache->delete($cacheKey);
            expect($trackableCache->getOperationsCount('delete'))->toBe(1);

            // 5. Third GET: Cache miss, repopulates the cache.
            await(http()->cacheWith($cacheConfig)->get($url));
            expect($trackableCache->getOperationsCount('set'))->toBe(2);
        });
    });

    test('generateCacheKey provides the correct key for invalidation', function () {
        $handlerMock = Mockery::mock(HttpHandler::class.'[fetch]');

        $handlerMock->shouldReceive('fetch')
            ->andReturn(resolved(new Response('{"data":"mocked"}', 200)))
        ;

        Http::setInstance($handlerMock);

        $trackableCache = new TrackableCacheTest;
        $cacheConfig = new CacheConfig(cache: $trackableCache);
        $url = 'https://api.example.com/resource/42';

        run(function () use ($trackableCache, $cacheConfig, $url) {
            await(http()->cacheWith($cacheConfig)->get($url));
            $internalKey = $trackableCache->getLastOperation('set')[1];
            $publicKey = HttpHandler::generateCacheKey($url);
            expect($publicKey)->toBe($internalKey);
        });
    });
});
