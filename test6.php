<?php

/**
 * Final Performance Benchmark: AsyncDb vs. Synchronous PDO.
 *
 * This script uses MySQL's SLEEP() function to simulate real-world network latency,
 * providing a definitive comparison of I/O-bound performance.
 */

require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Facades\AsyncLoop;
use Rcalicdan\FiberAsync\Facades\AsyncDb;
use Rcalicdan\FiberAsync\Facades\Async;
use Rcalicdan\FiberAsync\Config\ConfigLoader;

// --- Configuration ---
$queryCount = 100;      // How many queries to run.
$latencySeconds = 0.1; // 20ms of latency per query.
$sql = "SELECT SLEEP({$latencySeconds})"; // The query that creates the latency.

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
 * Performs the workload using standard, blocking PDO.
 */
function run_sync_benchmark(array $dbConfig, int $count, string $query): array
{
    echo "\n-- [SYNC] Running benchmark... --\n";
    $dsn = build_dsn_from_config($dbConfig);
    $pdo = new PDO($dsn, $dbConfig['username'] ?? null, $dbConfig['password'] ?? null);

    $startTime = microtime(true);
    $startMemory = memory_get_usage();

    for ($i = 0; $i < $count; $i++) {
        // Each call BLOCKS the script for the full duration of SLEEP().
        $pdo->query($query);
    }

    $endTime = microtime(true);
    $endMemory = memory_get_peak_usage();
    echo "[SYNC] Test complete.\n";
    return ['time' => $endTime - $startTime, 'memory' => $endMemory - $startMemory];
}

/**
 * Performs the exact same workload using your elegant AsyncDb facade.
 */
function run_async_benchmark(int $count, string $query): array
{
    echo "\n-- [ASYNC] Running benchmark... --\n";

    return AsyncLoop::run(function () use ($count, $query) {

        $startTime = microtime(true);
        $startMemory = memory_get_usage();
        
        $tasks = [];
        for ($i = 0; $i < $count; $i++) {
            $tasks[] = AsyncDb::raw($query);
        }

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
    echo "  FINAL BENCHMARK: HIGH-LATENCY MYSQL WORKLOAD\n";
    echo "==================================================================\n";

    // Load the configuration once to use for the sync test.
    $configLoader = ConfigLoader::getInstance();
    $dbConfigAll = $configLoader->get('database');
    $defaultConnection = $dbConfigAll['default'];
    $mysqlConfig = $dbConfigAll['connections'][$defaultConnection];
    $poolSize = $dbConfigAll['pool_size'];
    
    echo "Configuration loaded for default connection: '{$defaultConnection}'\n";
    echo "($queryCount queries, $poolSize concurrent connections, " . ($latencySeconds * 1000) . "ms DB latency per query)\n";

    // --- Run Tests ---
    $syncResult = run_sync_benchmark($mysqlConfig, $queryCount, $sql);
    $asyncResult = run_async_benchmark($queryCount, $sql);

    // --- Final Report ---
    $improvement = ($syncResult['time'] - $asyncResult['time']) / $syncResult['time'] * 100;

    echo "\n\n==================================================================\n";
    echo "                      PERFORMANCE REPORT                      \n";
    echo "==================================================================\n";
    echo "| Mode              | Execution Time      | Peak Memory Usage   |\n";
    echo "|-------------------|---------------------|---------------------|\n";
    printf("| Synchronous PDO   | %-19s | %-19s |\n", number_format($syncResult['time'], 4) . ' s', number_format($syncResult['memory'] / 1024, 2) . ' KB');
    printf("| AsyncDb Facade    | %-19s | %-19s |\n", number_format($asyncResult['time'], 4) . ' s', number_format($asyncResult['memory'] / 1024, 2) . ' KB');
    echo "==================================================================\n\n";
    
    printf("\033[1;32mConclusion: Your AsyncDb facade was %.2f%% faster than standard PDO.\033[0m\n", $improvement);
    $theoreticalSync = $queryCount * $latencySeconds;
    $theoreticalAsync = ceil($queryCount / $poolSize) * $latencySeconds;
    echo "Theoretical Sync Time:   " . number_format($theoreticalSync, 2) . "s. Real Sync Time: " . number_format($syncResult['time'], 2) . "s.\n";
    echo "Theoretical Async Time:  " . number_format($theoreticalAsync, 2) . "s. Real Async Time: " . number_format($asyncResult['time'], 2) . "s.\n";

} catch (Throwable $e) {
    echo "\n\n--- A TEST FAILED ---\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " on line " . $e->getLine() . "\n";
    echo $e->getTraceAsString() . "\n";
}