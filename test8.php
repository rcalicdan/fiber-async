<?php

/**
 * Final Performance Benchmark: AsyncDb Facade vs. Synchronous PDO.
 *
 * This script correctly simulates a real-world I/O workload by combining a
 * high-level facade call (AsyncDb::raw) with a cooperative wait (Async::delay)
 * inside a new async task. This is the canonical pattern for a complex async workflow.
 */

require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Facades\AsyncLoop;
use Rcalicdan\FiberAsync\Facades\AsyncDb;
use Rcalicdan\FiberAsync\Facades\Async;
use Rcalicdan\FiberAsync\Config\ConfigLoader;

// --- Configuration ---
$queryCount = 100;
$latencySeconds = 0.02; // 20ms of simulated I/O wait per operation.
$sql = "SELECT 1";      // A fast, non-blocking query.

// --- Helper to build a DSN string for the sync test ---
function build_dsn_from_config(array $config): string
{
    $driver = $config['driver'];
    if ($driver !== 'mysql') {
        throw new \InvalidArgumentException('This benchmark is configured for MySQL.');
    }
    return sprintf("mysql:host=%s;port=%d;dbname=%s;charset=%s",
        $config['host'] ?? 'localhost', $config['port'] ?? 3306,
        $config['database'] ?? '', $config['charset'] ?? 'utf8mb4'
    );
}

// =================================================================
// == WORKLOAD DEFINITIONS
// =================================================================

/**
 * Performs the workload using standard, blocking PDO and blocking sleep.
 */
function run_sync_benchmark(array $dbConfig, int $count, string $query, float $latency): array
{
    echo "\n-- [SYNC] Running benchmark with usleep()... --\n";
    $dsn = build_dsn_from_config($dbConfig);
    $pdo = new PDO($dsn, $dbConfig['username'] ?? null, $dbConfig['password'] ?? null);

    $startTime = microtime(true);
    $startMemory = memory_get_usage();

    for ($i = 0; $i < $count; $i++) {
        $pdo->query($query);
        // This simulates I/O wait by BLOCKING the entire process.
        usleep((int)($latency * 1000000));
    }

    $endTime = microtime(true);
    $endMemory = memory_get_peak_usage();
    echo "[SYNC] Test complete.\n";
    return ['time' => $endTime - $startTime, 'memory' => $endMemory - $startMemory];
}

/**
 * Performs the workload using the AsyncDb facade and cooperative delay.
 */
function run_async_benchmark(int $count, string $query, float $latency): array
{
    echo "\n-- [ASYNC] Running benchmark with await(delay())... --\n";

    return AsyncLoop::run(function () use ($count, $query, $latency) {
        $startTime = microtime(true);
        $startMemory = memory_get_usage();
        
        $tasks = [];
        for ($i = 0; $i < $count; $i++) {
            /**
             * --- THIS IS THE KEY PATTERN ---
             * We create a new async task that performs a sequence of await-able actions.
             * This entire block becomes a single promise that can be run in parallel.
             */
            $tasks[] = Async::async(function() use ($query, $latency) {
                // 1. Await the database query using the high-level facade.
                await(AsyncDb::raw($query));
                
                // 2. Await the cooperative delay.
                await(Async::delay($latency));
            })();
        }

        // Run all 100 tasks concurrently, up to the pool limit.
        await(Async::all($tasks));
        
        $endTime = microtime(true);
        $endMemory = memory_get_peak_usage();
        echo "[ASYNC] Test complete.\n";
        return ['time' => $endTime - $startTime, 'memory' => $endMemory - $startMemory];
    });
}

// =================================================================
// == MAIN EXECUTION AND REPORTING
// =================================================================

try {
    echo "==================================================================\n";
    echo "  FINAL BENCHMARK: COOPERATIVE MULTITASKING (HIGH-LEVEL FACADE)\n";
    echo "==================================================================\n";

    $configLoader = ConfigLoader::getInstance();
    $dbConfigAll = $configLoader->get('database');
    $defaultConnection = $dbConfigAll['default'];
    $mysqlConfig = $dbConfigAll['connections'][$defaultConnection];
    $poolSize = $dbConfigAll['pool_size'];
    
    echo "Configuration loaded for default connection: '{$defaultConnection}'\n";
    echo "($queryCount queries, $poolSize concurrent connections, " . ($latencySeconds * 1000) . "ms simulated I/O wait per query)\n";

    // --- Run Tests ---
    $syncResult = run_sync_benchmark($mysqlConfig, $queryCount, $sql, $latencySeconds);
    $asyncResult = run_async_benchmark($queryCount, $sql, $latencySeconds);

    // --- Final Report ---
    $improvement = ($syncResult['time'] - $asyncResult['time']) / ($syncResult['time'] ?: 1) * 100;

    echo "\n\n==================================================================\n";
    echo "                      PERFORMANCE REPORT                      \n";
    echo "==================================================================\n";
    echo "| Mode                   | Execution Time      | Peak Memory Usage   |\n";
    echo "|------------------------|---------------------|---------------------|\n";
    printf("| Synchronous PDO        | %-19s | %-19s |\n", number_format($syncResult['time'], 4) . ' s', number_format($syncResult['memory'] / 1024, 2) . ' KB');
    printf("| AsyncDb Facade (Co-op) | %-19s | %-19s |\n", number_format($asyncResult['time'], 4) . ' s', number_format($asyncResult['memory'] / 1024, 2) . ' KB');
    echo "==================================================================\n\n";
    
    printf("\033[1;32mConclusion: Your cooperative async framework was %.2f%% faster than standard blocking code.\033[0m\n", $improvement);
    $theoreticalSync = $queryCount * $latencySeconds;
    $theoreticalAsync = ceil($queryCount / $poolSize) * $latencySeconds;
    echo "Theoretical Sync Time:   " . number_format($theoreticalSync, 2) . "s. Real Sync Time: " . number_format($syncResult['time'], 2) . "s.\n";
    echo "Theoretical Async Time:  " . number_format($theoreticalAsync, 2) . "s. Real Async Time: " . number_format($asyncResult['time'], 2) . "s.\n";

} catch (Throwable $e) {
    echo "\n\n--- A TEST FAILED ---\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}