<?php

/**
 * High-Latency Performance Benchmark for the AsyncPDO Facade using SQLite.
 *
 * This script demonstrates the power and simplicity of the new facade by running
 * a high-volume, I/O-bound workload and comparing it to standard PDO.
 */

require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Facades\Async;
use Rcalicdan\FiberAsync\Facades\AsyncLoop;
use Rcalicdan\FiberAsync\Facades\AsyncPDO;
use Rcalicdan\FiberAsync\Database\DatabaseConfigFactory;



$dbConfig = DatabaseConfigFactory::sqlite('file::memory:?cache=shared');
$queryCount = 200;
$poolSize = 10;
$latencySeconds = 0.01; 

function setup_database(PDO $pdo, int $rowCount): void
{
    $pdo->exec("DROP TABLE IF EXISTS perf_test");
    $pdo->exec("CREATE TABLE perf_test (id INTEGER PRIMARY KEY, data TEXT)");
    $stmt = $pdo->prepare("INSERT INTO perf_test (data) VALUES (?)");
    for ($i = 0; $i < $rowCount; $i++) {
        $stmt->execute(['data-' . $i]);
    }
}

// =================================================================
// == STEP 1: DEFINE THE BENCHMARK WORKLOADS
// =================================================================

/**
 * Performs the workload using standard, blocking PDO.
 */
function run_sync_benchmark(array $config, int $count, float $latency): array
{
    echo "\n-- [SYNC] Running benchmark... --\n";
    $pdo = new PDO('sqlite:' . $config['database']);
    setup_database($pdo, $count);
    $stmt = $pdo->prepare("SELECT id FROM perf_test WHERE id = ?");

    $startTime = microtime(true);
    $startMemory = memory_get_usage();

    for ($i = 0; $i < $count; $i++) {
        $stmt->execute([rand(1, $count)]);
        $stmt->fetch();
        // This BLOCKS the entire script for the latency duration.
        usleep((int)($latency * 1000000));
    }

    $endTime = microtime(true);
    $endMemory = memory_get_peak_usage();
    echo "[SYNC] Test complete.\n";
    return ['time' => $endTime - $startTime, 'memory' => $endMemory - $startMemory];
}

/**
 * Performs the exact same workload using the elegant AsyncPDO facade.
 */
function run_async_benchmark(array $config, int $count, float $latency, int $poolSize): array
{
    echo "\n-- [ASYNC] Running benchmark... --\n";

    // Initialize the entire async database system with one simple call.
    AsyncPDO::init($config, $poolSize);

    // The entire test is wrapped in AsyncLoop::run
    return AsyncLoop::run(function () use ($count, $latency) {
        // Setup the DB using the facade itself
        await(AsyncPDO::run(fn(PDO $pdo) => setup_database($pdo, $count)));

        $startTime = microtime(true);
        $startMemory = memory_get_usage();
        
        $tasks = [];
        for ($i = 0; $i < $count; $i++) {
            // Use the elegant AsyncPDO::run method which handles all pooling logic.
            $tasks[] = AsyncPDO::run(function(PDO $pdo) use ($latency, $count) {
                $stmt = $pdo->prepare("SELECT id FROM perf_test WHERE id = ?");
                $stmt->execute([rand(1, $count)]);
                $stmt->fetch();
                // This SUSPENDS the Fiber, allowing the event loop to run other queries.
                await(Async::delay($latency));
            });
        }

        // Run all 200 tasks concurrently (up to the pool limit of 10)
        await(Async::all($tasks));
        
        $endTime = microtime(true);
        $endMemory = memory_get_peak_usage();
        echo "[ASYNC] Test complete.\n";
        return ['time' => $endTime - $startTime, 'memory' => $endMemory - $startMemory];
    });
}

// =================================================================
// == STEP 2: MAIN EXECUTION AND REPORTING
// =================================================================

try {
    echo "Preparing SQLite Benchmark...\n";
    echo "($queryCount queries, $poolSize concurrent connections, " . ($latencySeconds * 1000) . "ms simulated latency per query)\n";

    // --- Run Tests ---
    $syncResult = run_sync_benchmark($dbConfig, $queryCount, $latencySeconds);
    $asyncResult = run_async_benchmark($dbConfig, $queryCount, $latencySeconds, $poolSize);

    // --- Final Report ---
    $improvement = ($syncResult['time'] - $asyncResult['time']) / $syncResult['time'] * 100;

    echo "\n\n==================================================================\n";
    echo "                      SQLITE BENCHMARK REPORT                   \n";
    echo "==================================================================\n";
    echo "| Mode              | Execution Time      | Peak Memory Usage   |\n";
    echo "|-------------------|---------------------|---------------------|\n";
    printf("| Synchronous PDO   | %-19s | %-19s |\n", number_format($syncResult['time'], 4) . ' s', number_format($syncResult['memory'] / 1024, 2) . ' KB');
    printf("| AsyncPDO Facade   | %-19s | %-19s |\n", number_format($asyncResult['time'], 4) . ' s', number_format($asyncResult['memory'] / 1024, 2) . ' KB');
    echo "==================================================================\n\n";
    
    printf("Conclusion: Your AsyncPDO facade was %.2f%% faster than standard PDO.\n", $improvement);
    echo "Theoretical Sync Time: " . number_format($queryCount * $latencySeconds, 2) . "s. Real Sync Time: " . number_format($syncResult['time'], 2) . "s.\n";
    echo "Theoretical Async Time: " . number_format(ceil($queryCount / $poolSize) * $latencySeconds, 2) . "s. Real Async Time: " . number_format($asyncResult['time'], 2) . "s.\n";


} catch (Throwable $e) {
    echo "\n\n--- A TEST FAILED ---\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " on line " . $e->getLine() . "\n";
    echo $e->getTraceAsString() . "\n";
}