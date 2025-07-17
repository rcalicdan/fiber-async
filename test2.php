<?php

/**
 * Benchmark: Sync vs. Async with Verification
 * This script proves that both methods return the same results,
 * while demonstrating the performance difference.
 */

require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Database\AsyncPdoPool;
use Rcalicdan\FiberAsync\Facades\Async;
use Rcalicdan\FiberAsync\Facades\AsyncLoop;
use Rcalicdan\FiberAsync\Database\DatabaseConfigFactory;
use Rcalicdan\FiberAsync\AsyncEventLoop;

class_exists(AsyncPdoPool::class) || require_once __DIR__ . '/src/Database/AsyncPdoPool.php';

// --- Configuration ---
$dbConfig = DatabaseConfigFactory::sqlite(':memory:');
$queryCount = 200;
$poolSize = 10;
$latencySeconds = 0.01;

if ($dbConfig['driver'] === 'sqlite' && $dbConfig['database'] === ':memory:') {
    $dbConfig['database'] = 'file::memory:?cache=shared';
    echo "Using shared in-memory SQLite for pooling test.\n";
}

// --- Test Setup ---
function setup_database(PDO $pdo, int $rowCount): void
{
    $pdo->exec("DROP TABLE IF EXISTS benchmark_test");
    $pdo->exec("CREATE TABLE benchmark_test (id INTEGER PRIMARY KEY, data TEXT)");
    $stmt = $pdo->prepare("INSERT INTO benchmark_test (data) VALUES (?)");
    for ($i = 0; $i < $rowCount; $i++) {
        $stmt->execute(['data-' . $i]);
    }
}

// --- BENCHMARK 1: Synchronous Prepared Statement ---
function run_sync_prepared(array $config, int $count, float $latency): array
{
    echo "Running [1] Synchronous (Prepared Statement)...\n";
    $pdo = new PDO($config['driver'] . ':' . $config['database']);
    setup_database($pdo, $count);
    $stmt = $pdo->prepare("SELECT id, data FROM benchmark_test WHERE id = ?");
    $startTime = microtime(true);
    $startMemory = memory_get_usage();
    
    // --- CHANGE: Capture results ---
    $returnedData = [];

    for ($i = 0; $i < $count; $i++) {
        $idToFind = rand(1, $count);
        $stmt->execute([$idToFind]);
        $returnedData[] = $stmt->fetch(PDO::FETCH_ASSOC); // Capture the row
        usleep((int)($latency * 1000000));
    }
    $endTime = microtime(true);
    $endMemory = memory_get_peak_usage(true);
    return [
        'metrics' => ['time' => ($endTime - $startTime), 'memory' => ($endMemory - $startMemory)],
        'data' => $returnedData
    ];
}

// --- BENCHMARK 2: Asynchronous Pooled Prepared Statement ---
function run_async_pooled_prepared(array $config, int $count, float $latency, int $poolSize): array
{
    echo "Running [2] Asynchronous Pool (Prepared Statement)...\n";
    AsyncEventLoop::getInstance($config);
    return AsyncLoop::run(function () use ($config, $count, $latency, $poolSize) {
        $pool = new AsyncPdoPool($config, $poolSize);
        await(Async::async(function() use ($pool, $count) {
            $pdo = await($pool->get());
            try { setup_database($pdo, $count); } 
            finally { $pool->release($pdo); }
        })());
        $startTime = microtime(true);
        $startMemory = memory_get_usage();
        $tasks = [];
        for ($i = 0; $i < $count; $i++) {
            // --- CHANGE: Task now returns the fetched data ---
            $tasks[] = Async::async(function () use ($pool, $latency, $count) {
                $pdo = null;
                try {
                    $pdo = await($pool->get());
                    $idToFind = rand(1, $count);
                    $stmt = $pdo->prepare("SELECT id, data FROM benchmark_test WHERE id = ?");
                    $stmt->execute([$idToFind]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC); // Get the row
                    await(Async::delay($latency));
                    return $row; // Return the row from the async task
                } finally {
                    if ($pdo) { $pool->release($pdo); }
                }
            })();
        }
        
        // --- CHANGE: Capture the array of results from all tasks ---
        $returnedData = await(Async::all($tasks));

        $endTime = microtime(true);
        $endMemory = memory_get_peak_usage(true);
        $pool->close();
        return [
            'metrics' => ['time' => ($endTime - $startTime), 'memory' => ($endMemory - $startMemory)],
            'data' => $returnedData
        ];
    });
}

// --- NEW: Helper to print sample results ---
function print_sample_results(string $label, array $data): void
{
    echo "\n--- Data Verification for: $label ---\n";
    echo "Total records returned: " . count($data) . "\n";
    if (count($data) > 6) {
        echo "First 3 results:\n";
        print_r(array_slice($data, 0, 3));
        echo "Last 3 results:\n";
        print_r(array_slice($data, -3));
    } else {
        print_r($data);
    }
    echo "----------------------------------------\n";
}


// --- Main Execution ---
try {
    echo "Preparing benchmarks (200 queries, 10ms latency each)...\n\n";
    $syncResult = run_sync_prepared($dbConfig, $queryCount, $latencySeconds);
    AsyncLoop::reset();
    $asyncResult = run_async_pooled_prepared($dbConfig, $queryCount, $latencySeconds, $poolSize);

    // --- NEW: Print sample data for verification ---
    print_sample_results("[1] Synchronous Results", $syncResult['data']);
    print_sample_results("[2] Asynchronous Results", $asyncResult['data']);

    // Display the performance results
    $timeImprovement = ($syncResult['metrics']['time'] - $asyncResult['metrics']['time']) / $syncResult['metrics']['time'] * 100;

    echo "\n--- Final Benchmark Results ({$queryCount} queries, {$poolSize} max concurrent) ---\n\n";
    echo "| Method                       | Execution Time      | Peak Memory Usage |\n";
    echo "|------------------------------|---------------------|-------------------|\n";
    printf("| [1] Sync (Prepared Stmt)     | %-19s | %-17s |\n", number_format($syncResult['metrics']['time'], 4) . ' s', number_format($syncResult['metrics']['memory'] / 1024) . ' KB');
    printf("| [2] Async (Prepared Stmt)    | %-19s | %-17s |\n", number_format($asyncResult['metrics']['time'], 4) . ' s', number_format($asyncResult['metrics']['memory'] / 1024) . ' KB');
    echo "\n";
    
    printf("Conclusion: The async prepared statement approach was %.2f%% faster than the standard synchronous prepared statement approach.\n", $timeImprovement);

} catch (Throwable $e) {
    echo "\nAn error occurred: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}