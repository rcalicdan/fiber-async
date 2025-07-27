<?php

require 'vendor/autoload.php';

use Psr\SimpleCache\CacheInterface;
use Rcalicdan\FiberAsync\Http\CacheConfig;
use Rcalicdan\FiberAsync\Http\Handlers\HttpHandler; // <-- Import the handler
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

/**
 * A simple service class to demonstrate cache invalidation.
 */
class UserService
{
    private CacheConfig $cacheConfig;
    private string $baseApiUrl = 'https://jsonplaceholder.typicode.com';

    public function __construct(CacheInterface $cache)
    {
        // The service gets the shared cache instance and creates a config for it.
        $this->cacheConfig = new CacheConfig(
            ttlSeconds: 86400, // Cache users for a full day by default
            cache: $cache
        );
    }

    /**
     * Fetches a single user. This operation is aggressively cached.
     */
    public function getUser(int $id): array
    {
        $url = "{$this->baseApiUrl}/users/{$id}";
        echo "--> Requesting: GET {$url}\n";
        
        $response = await(
            http()->cacheWith($this->cacheConfig)->get($url)
        );
        
        return $response->json();
    }

    /**
     * Updates a user's data and, on success, invalidates the cache entry for that user.
     */
    public function updateUser(int $id, array $data): array
    {
        $url = "{$this->baseApiUrl}/users/{$id}";
        echo "--> Requesting: PUT {$url}\n";

        // Perform the mutation (this is never cached).
        $response = await(
            http()->put($url, $data)
        );
        
        // --- THIS IS THE INVALIDATION LOGIC ---
        if ($response->ok()) {
            // 1. Get the EXACT cache key that the HttpHandler would use for this URL.
            $cacheKey = HttpHandler::generateCacheKey($url);
            
            // 2. Manually delete that key from the shared cache.
            $this->cacheConfig->cache->delete($cacheKey);
            
            echo "    SUCCESS: Update successful. Cache invalidated for key: {$cacheKey}\n";
        }
        
        return $response->json();
    }
}

// --- Application Setup ---
// In a real app, this comes from your dependency injection container.
$sharedCache = new Psr16Cache(new FilesystemAdapter());
$sharedCache->clear(); // Start fresh for the demo.
$userService = new UserService($sharedCache);
$userId = 1;
$userUrl = "https://jsonplaceholder.typicode.com/users/{$userId}";


// --- Main Application Flow ---
run(function () use ($userService, $sharedCache, $userId, $userUrl) {
    echo "====================================================\n";
    echo "Cache Invalidation Test\n";
    echo "====================================================\n\n";

    // 1. First fetch: This will go to the network and populate the cache.
    echo "[RUN 1] First fetch. Expect a network call.\n";
    $start1 = microtime(true);
    $user1 = $userService->getUser($userId);
    echo "    Fetched user '{$user1['name']}' in " . number_format(microtime(true) - $start1, 4) . "s\n";
    $cacheKey = HttpHandler::generateCacheKey($userUrl);
    if ($sharedCache->has($cacheKey)) {
        echo "    ✅ Cache is now POPULATED.\n\n";
    }

    // 2. Second fetch: This should be served instantly from the cache.
    echo "[RUN 2] Second fetch. Expect a cache hit.\n";
    $start2 = microtime(true);
    $user2 = $userService->getUser($userId);
    echo "    Fetched user '{$user2['name']}' from cache in " . number_format(microtime(true) - $start2, 4) . "s\n\n";
    
    // 3. Update the user. This is the "event" that triggers the invalidation.
    echo "[RUN 3] Update the user's name. This will clear the cache.\n";
    $userService->updateUser($userId, ['name' => 'Reymart Calicdan', 'email' => 'reymart@example.com']);
    if (!$sharedCache->has($cacheKey)) {
        echo "    ✅ Cache is now CLEARED.\n\n";
    }

    // 4. Third fetch: The cache is empty, so this MUST go back to the network to get the fresh data.
    echo "[RUN 4] Third fetch. Expect a network call to get fresh data.\n";
    $start4 = microtime(true);
    $user3 = $userService->getUser($userId);
    echo "    Fetched refreshed user '{$user3['name']}' in " . number_format(microtime(true) - $start4, 4) . "s\n";
    if ($sharedCache->has($cacheKey)) {
        echo "    ✅ Cache is now RE-POPULATED with the new data.\n\n";
    }
});