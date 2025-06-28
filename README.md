# ⚡ FiberAsync: Effortless Asynchronous PHP with Fibers

**FiberAsync** is a blazing-fast PHP library that brings JavaScript-style `async/await` power to native PHP — all without extra extensions. Powered by **Fibers**, it lets you write elegant, concurrent code that feels synchronous. Use the static `Async` facade or the intuitive global helpers to run tasks in parallel like a pro. Perfect for APIs, automation, and high-performance PHP scripts.

> ✅ **No need to install PHP extensions**
> ✅ **No `pcntl` or special functions required**
> ✅ **Runs everywhere** — shared hosting, CLI, or production servers

## 📦 Installation

### ✅ Prerequisites

* **PHP 8.2 or higher** is required.
  This library uses native [Fibers](https://www.php.net/manual/en/class.fiber.php), which are available starting from PHP 8.1 — but this library targets **PHP 8.2+** for best performance and language features.
* No need for special PHP extensions or `pcntl`.
* Works on shared hosting, CLI, or any standard PHP environment.

### 💡 Composer (Recommended)

If you're using Composer, just run:

```bash
composer require rcalicdan/fiber-async
```

### 🖐️ Manual Installation

If you're not using Composer, you can manually include the files:

```php
require_once 'Async.php';
require_once 'async_helper.php';
require_once 'loop_helper.php';
```

That's it! You're ready to write async PHP code with ease.

---

Let me know if you'd like to include a section about publishing it to Packagist, or writing a `composer.json` template.

## Sample Usage

You can use this library in two ways:

1. **Static Facade**: `Rcalicdan\FiberAsync\Facades\Async::methodName()`
2. **Global Helper Functions**: Direct function calls like `async()`, `await()`, etc.

Both approaches provide identical functionality - choose the one that fits your coding style preference.

## Core Concepts

### Fibers and Async Context

Before diving into async operations, it's important to understand when you're in a fiber context:

```php
// Using facade
if (Async::inFiber()) {
    echo "Running in fiber context";
}

// Using helper function
if (in_fiber()) {
    echo "Running in fiber context";
}
```

## Basic Async Operations

### Creating Async Functions

Convert regular functions into async functions that return promises:

```php
// Using facade
$asyncFunction = Async::async(function() {
    // This function can now use await and other async operations
    $response = Async::await(Async::fetch('https://api.example.com/data'));
    return $response['data'];
});

// Using helper function
$asyncFunction = async(function() {
    $response = await(fetch('https://api.example.com/data'));
    return $response['data'];
});

// Execute the async function
$promise = $asyncFunction();
```

### Awaiting Promises

Suspend execution until a promise resolves:

```php
$asyncOperation = Async::async(function() {
    // Make multiple HTTP requests concurrently
    $promise1 = Async::fetch('https://api.example.com/users');
    $promise2 = Async::fetch('https://api.example.com/posts');

    // Wait for both to complete
    $users = Async::await($promise1);
    $posts = Async::await($promise2);

    return ['users' => $users, 'posts' => $posts];
});

// Or using helper functions
$asyncOperation = async(function() {
    $promise1 = fetch('https://api.example.com/users');
    $promise2 = fetch('https://api.example.com/posts');

    $users = await($promise1);
    $posts = await($promise2);

    return ['users' => $users, 'posts' => $posts];
});
```

## HTTP Operations

### Basic Fetch

Perform asynchronous HTTP requests:

```php
// Using facade
$promise = Async::fetch('https://api.github.com/users/octocat', [
    'method' => 'GET',
    'headers' => ['User-Agent' => 'MyApp/1.0'],
    'timeout' => 10
]);

// Using helper function
$promise = fetch('https://api.github.com/users/octocat', [
    'method' => 'GET',
    'headers' => ['User-Agent' => 'MyApp/1.0'],
    'timeout' => 10
]);

// Execute with automatic loop management
$userData = Async::run($promise);
```

### POST Requests

```php
$postData = [
    'name' => 'John Doe',
    'email' => 'john@example.com'
];

$promise = Async::fetch('https://api.example.com/users', [
    'method' => 'POST',
    'headers' => ['Content-Type' => 'application/json'],
    'body' => json_encode($postData)
]);

$result = Async::run($promise);
```

### Guzzle Integration

For advanced HTTP operations with Guzzle-specific features:

```php
// Using facade
$promise = Async::guzzle('GET', 'https://api.example.com/data', [
    'headers' => ['Authorization' => 'Bearer token'],
    'query' => ['limit' => 10, 'offset' => 0]
]);

// Using helper function
$promise = async_guzzle('GET', 'https://api.example.com/data', [
    'headers' => ['Authorization' => 'Bearer token'],
    'query' => ['limit' => 10, 'offset' => 0]
]);
```

## Promise Combinators

### Waiting for All Promises

Execute multiple operations and wait for all to complete:

```php
$promises = [
    Async::fetch('https://api.example.com/users/1'),
    Async::fetch('https://api.example.com/users/2'),
    Async::fetch('https://api.example.com/users/3')
];

// All promises must resolve
$allResults = Async::run(Async::all($promises));

// Using helper functions
$allResults = run(all($promises));
```

### Racing Promises

Get the result of the first promise to settle:

```php
$promises = [
    Async::fetch('https://fast-api.example.com/data'),
    Async::fetch('https://slow-api.example.com/data'),
    Async::delay(5) // Timeout after 5 seconds
];

// Returns result from whichever completes first
$fastestResult = Async::run(Async::race($promises));

// Using helper functions
$fastestResult = run(race($promises));
```

## Timing Operations

### Delays

Create non-blocking delays:

```php
$delayedOperation = Async::async(function() {
    echo "Starting operation...\n";

    // Wait 2 seconds without blocking
    Async::await(Async::delay(2));

    echo "Operation completed after delay\n";
    return "Done";
});

// Or using helper functions
$delayedOperation = async(function() {
    echo "Starting operation...\n";
    await(delay(2));
    echo "Operation completed after delay\n";
    return "Done";
});

$result = Async::run($delayedOperation);
```

### Async Sleep

Simple sleep with automatic loop management:

```php
// Sleep for 1.5 seconds
Async::asyncSleep(1.5);

// Using helper function
async_sleep(1.5);
```

## Concurrency Control

### Limited Concurrency

Process many tasks with concurrency limits to avoid overwhelming the system:

```php
// Create 100 HTTP requests
$tasks = [];
for ($i = 1; $i <= 100; $i++) {
    $tasks[] = function() use ($i) {
        return Async::fetch("https://api.example.com/item/{$i}");
    };
}

// Execute with maximum 10 concurrent requests
$results = Async::run(Async::concurrent($tasks, 10));

// Using helper functions
$results = run(concurrent($tasks, 10));
```

### Practical Concurrency Example

```php
// Scrape multiple websites concurrently
$urls = [
    'https://news.example.com',
    'https://blog.example.com',
    'https://docs.example.com'
];

$scrapeTasks = array_map(function($url) {
    return async(function() use ($url) {
        $response = await(fetch($url));
        // Process the HTML content
        return [
            'url' => $url,
            'title' => extractTitle($response['body']),
            'content_length' => strlen($response['body'])
        ];
    });
}, $urls);

// Execute all scraping tasks with concurrency limit of 3
$results = run_concurrent($scrapeTasks, 3);
```

## Event Loop Management

### Running Single Operations

Execute async operations with automatic loop management:

```php
// Simple execution
$result = Async::run(function() {
    return Async::await(Async::fetch('https://api.example.com/data'));
});

// Using helper function
$result = run(function() {
    return await(fetch('https://api.example.com/data'));
});
```

### Running Multiple Operations

Execute multiple operations concurrently:

```php
$operations = [
    async(function() { return await(fetch('https://api.example.com/users')); }),
    async(function() { return await(fetch('https://api.example.com/posts')); }),
    async(function() { return await(fetch('https://api.example.com/comments')); })
];

// Run all operations concurrently
$results = Async::runAll($operations);

// Using helper function
$results = run_all($operations);
```

### Running with Concurrency Limits

```php
// Many operations with concurrency control
$operations = [];
for ($i = 1; $i <= 50; $i++) {
    $operations[] = async(function() use ($i) {
        return await(fetch("https://api.example.com/item/{$i}"));
    });
}

// Run with maximum 5 concurrent operations
$results = Async::runConcurrent($operations, 5);

// Using helper function
$results = run_concurrent($operations, 5);
```

## Error Handling

### Safe Async Functions

Create async functions with automatic error handling:

```php
$safeFunction = Async::tryAsync(function() {
    // This might throw an exception
    $data = Async::await(Async::fetch('https://unreliable-api.example.com'));
    return processData($data);
});

// Using helper function
$safeFunction = try_async(function() {
    $data = await(fetch('https://unreliable-api.example.com'));
    return processData($data);
});

// Exceptions are converted to rejected promises
$promise = $safeFunction();
```

### Manual Promise Resolution/Rejection

```php
// Create resolved promises
$resolvedPromise = Async::resolve('Success!');
$resolvedPromise = resolve('Success!');

// Create rejected promises
$rejectedPromise = Async::reject(new Exception('Something went wrong'));
$rejectedPromise = reject(new Exception('Something went wrong'));
```

## Advanced Features

### Timeouts

Execute operations with time limits:

```php
$slowOperation = async(function() {
    await(delay(10)); // This takes 10 seconds
    return "Completed";
});

try {
    // Will timeout after 5 seconds
    $result = Async::runWithTimeout($slowOperation, 5);
} catch (Exception $e) {
    echo "Operation timed out: " . $e->getMessage();
}

// Using helper function
try {
    $result = run_with_timeout($slowOperation, 5);
} catch (Exception $e) {
    echo "Operation timed out: " . $e->getMessage();
}
```

### Benchmarking

Measure performance of async operations:

```php
$operation = async(function() {
    $responses = await(all([
        fetch('https://api.example.com/endpoint1'),
        fetch('https://api.example.com/endpoint2'),
        fetch('https://api.example.com/endpoint3')
    ]));
    return $responses;
});

$result = Async::benchmark($operation);
// $result contains:
// - 'result': the operation result
// - 'benchmark': performance metrics

echo "Execution time: " . $result['benchmark']['execution_time'] . "s\n";
echo "Memory used: " . $result['benchmark']['memory_used'] . " bytes\n";

// Using helper function
$result = benchmark($operation);
```

### Converting Synchronous Functions

Make blocking operations async-compatible:

```php
// Wrap a blocking database call
$asyncDbCall = Async::asyncify(function() {
    // This blocks but won't interfere with other async operations
    return $database->query('SELECT * FROM users');
});

// Using helper function
$asyncDbCall = asyncify(function() {
    return $database->query('SELECT * FROM users');
});

$users = Async::run($asyncDbCall);
```

### Wrapping Synchronous Operations

Convert sync operations to promises:

```php
$promise = Async::wrapSync(function() {
    // Some CPU-intensive operation
    return array_sum(range(1, 1000000));
});

// Using helper function
$promise = wrap_sync(function() {
    return array_sum(range(1, 1000000));
});

$result = Async::run($promise);
```

## Quick Utilities

### Quick HTTP Fetch

For simple HTTP requests with automatic loop management:

```php
// Returns response data directly
$data = Async::quickFetch('https://api.example.com/data');

// Using helper function
$data = quick_fetch('https://api.example.com/data');
```

### Simple Tasks

For quick async tasks:

```php
$result = Async::task(function() {
    $response = await(fetch('https://api.example.com/status'));
    return $response['status'] === 'ok';
});

// Using helper function
$result = task(function() {
    $response = await(fetch('https://api.example.com/status'));
    return $response['status'] === 'ok';
});
```

## Practical Examples

### Web Scraping with Rate Limiting

```php
function scrapeUrls(array $urls, int $concurrency = 3): array {
    $tasks = array_map(function($url) {
        return async(function() use ($url) {
            $response = await(fetch($url, ['timeout' => 30]));

            return [
                'url' => $url,
                'status' => $response['status'],
                'title' => extractTitle($response['body']),
                'scraped_at' => date('Y-m-d H:i:s')
            ];
        });
    }, $urls);

    return run_concurrent($tasks, $concurrency);
}

$urls = [
    'https://example.com/page1',
    'https://example.com/page2',
    'https://example.com/page3'
];

$results = scrapeUrls($urls, 2);
```

### API Data Aggregation

```php
function aggregateUserData(int $userId): array {
    return run(async(function() use ($userId) {
        // Fetch user data from multiple sources concurrently
        $promises = [
            'profile' => fetch("https://api.example.com/users/{$userId}"),
            'posts' => fetch("https://api.example.com/users/{$userId}/posts"),
            'comments' => fetch("https://api.example.com/users/{$userId}/comments"),
            'followers' => fetch("https://api.example.com/users/{$userId}/followers")
        ];

        $results = await(all($promises));

        return [
            'user_id' => $userId,
            'profile' => $results['profile'],
            'activity' => [
                'posts_count' => count($results['posts']),
                'comments_count' => count($results['comments']),
                'followers_count' => count($results['followers'])
            ],
            'fetched_at' => time()
        ];
    }));
}

$userData = aggregateUserData(123);
```

### Batch Processing with Progress

```php
function processBatch(array $items, int $concurrency = 5): array {
    $tasks = array_map(function($item, $index) {
        return async(function() use ($item, $index) {
            // Simulate processing time
            await(delay(rand(1, 3)));

            // Process the item
            $result = processItem($item);

            echo "Processed item " . ($index + 1) . "\n";

            return $result;
        });
    }, $items, array_keys($items));

    return run_concurrent($tasks, $concurrency);
}

$items = range(1, 20);
$results = processBatch($items, 3);
```

This documentation covers all the major features and usage patterns of the FiberAsync library. Remember that you can always choose between using the static facade (`Async::method()`) or the global helper functions (`method()`) based on your preference - both provide identical functionality.

## 🤝 Contributing

We welcome all contributions!

* Found a bug? 🐛 [Open an issue](../../issues) and let us know.
* Want to improve performance, add features, or clean up code? Submit a **pull request** — we'd love to see it.
* New to open source? No worries — FiberAsync is beginner-friendly and a great way to get started with async in PHP.

Your ideas and feedback are what make this project better. Let's build something awesome together!

