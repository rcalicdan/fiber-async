<?php

/**
 * Final Benchmark: High-Concurrency Transactional Workloads.
 *
 * This script simulates multiple, concurrent bulk-insert transactions to
 * test the framework's ability to handle complex, high-latency, parallel database work.
 */

require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Facades\AsyncLoop;
use Rcalicdan\FiberAsync\Facades\AsyncDb;
use Rcalicdan\FiberAsync\Facades\Async;
use Rcalicdan\FiberAsync\Config\ConfigLoader;

// --- Configuration ---
$numTransactions = 10;      // How many concurrent "checkouts" to simulate.
$insertsPerTransaction = 20; // How many items are in each checkout.
$latencySeconds = 0.005;    // 5ms of simulated work/wait after each item insert.

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
 * Performs the transactional workload using standard, blocking PDO.
 */
function run_sync_benchmark(array $dbConfig, int $transactionCount, int $insertCount, float $latency): array
{
    echo "\n-- [SYNC] Running transactional benchmark... --\n";
    $dsn = build_dsn_from_config($dbConfig);
    $pdo = new PDO($dsn, $dbConfig['username'] ?? null, $dbConfig['password'] ?? null);
    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, product_id INT)");
    $pdo->exec("TRUNCATE TABLE orders");

    $startTime = microtime(true);
    $startMemory = memory_get_usage();

    // Run each transaction sequentially.
    for ($i = 0; $i < $transactionCount; $i++) {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("INSERT INTO orders (user_id, product_id) VALUES (?, ?)");
            for ($j = 0; $j < $insertCount; $j++) {
                $stmt->execute([$i + 1, rand(100, 999)]);
                // This BLOCKS the entire process on every single insert.
                usleep((int)($latency * 1000000));
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
        }
    }

    $finalCount = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
    $endTime = microtime(true);
    $endMemory = memory_get_peak_usage();
    echo "[SYNC] Test complete. Total rows inserted: $finalCount\n";
    return ['time' => $endTime - $startTime, 'memory' => $endMemory - $startMemory, 'count' => $finalCount];
}

/**
 * Performs the exact same workload using the elegant AsyncDb facade.
 */
function run_async_benchmark(int $transactionCount, int $insertCount, float $latency): array
{
    echo "\n-- [ASYNC] Running transactional benchmark... --\n";

    return AsyncLoop::run(function () use ($transactionCount, $insertCount, $latency) {
        // Initial setup
        await(AsyncDb::rawExecute("CREATE TABLE IF NOT EXISTS orders (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, product_id INT)"));
        await(AsyncDb::rawExecute("TRUNCATE TABLE orders"));

        $startTime = microtime(true);
        $startMemory = memory_get_usage();
        
        $tasks = [];
        for ($i = 0; $i < $transactionCount; $i++) {
            $userId = $i + 1;
            // Create a promise for each concurrent transaction.
            $tasks[] = Async::async(function() use ($userId, $insertCount, $latency) {
                // The entire block is one atomic, concurrent operation.
                await(AsyncDb::transaction(function() use ($userId, $insertCount, $latency) {
                    for ($j = 0; $j < $insertCount; $j++) {
                        // The query builder is used inside the transaction.
                        await(AsyncDb::table('orders')->insert([
                            'user_id' => $userId,
                            'product_id' => rand(100, 999)
                        ]));
                        // The cooperative wait that makes this so fast.
                        await(Async::delay($latency));
                    }
                }));
            })();
        }

        // Run all transactions concurrently.
        await(Async::all($tasks));
        
        $finalCount = await(AsyncDb::table('orders')->count());
        $endTime = microtime(true);
        $endMemory = memory_get_peak_usage();
        echo "[ASYNC] Test complete. Total rows inserted: $finalCount\n";
        return ['time' => $endTime - $startTime, 'memory' => $endMemory - $startMemory, 'count' => $finalCount];
    });
}

// =================================================================
// == MAIN EXECUTION AND REPORTING
// =================================================================

try {
    echo "==================================================================\n";
    echo "  FINAL BENCHMARK: HIGH-CONCURRENCY TRANSACTIONS\n";
    echo "==================================================================\n";

    $configLoader = ConfigLoader::getInstance();
    $dbConfigAll = $configLoader->get('database');
    $defaultConnection = $dbConfigAll['default'];
    $mysqlConfig = $dbConfigAll['connections'][$defaultConnection];
    $poolSize = $dbConfigAll['pool_size'];
    
    echo "Configuration loaded for '{$defaultConnection}' (Pool size: {$poolSize})\n";
    echo "($numTransactions concurrent transactions, $insertsPerTransaction inserts each, " . ($latencySeconds * 1000) . "ms wait per insert)\n";

    // --- Run Tests ---
    $syncResult = run_sync_benchmark($mysqlConfig, $numTransactions, $insertsPerTransaction, $latencySeconds);
    $asyncResult = run_async_benchmark($numTransactions, $insertsPerTransaction, $latencySeconds);

    // --- Verification ---
    if ($syncResult['count'] !== $asyncResult['count'] || $syncResult['count'] !== ($numTransactions * $insertsPerTransaction)) {
        throw new \RuntimeException("Data verification failed! Sync count: {$syncResult['count']}, Async count: {$asyncResult['count']}");
    }

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
    
    printf("\033[1;32mConclusion: Your async transaction handling was %.2f%% faster than standard blocking code.\033[0m\n", $improvement);
    $totalInserts = $numTransactions * $insertsPerTransaction;
    $theoreticalSync = $totalInserts * $latencySeconds;
    $theoreticalAsync = (ceil($numTransactions / $poolSize) * $insertsPerTransaction) * $latencySeconds;
    echo "Theoretical Sync Time:   " . number_format($theoreticalSync, 2) . "s. Real Sync Time: " . number_format($syncResult['time'], 2) . "s.\n";
    echo "Theoretical Async Time:  " . number_format($theoreticalAsync, 2) . "s. Real Async Time: " . number_format($asyncResult['time'], 2) . "s.\n";

} catch (Throwable $e) {
    echo "\n\n--- A TEST FAILED ---\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}