<?php

/**
 * High-Latency Performance Benchmark for Async Database Libraries.
 *
 * This script provides a head-to-head comparison between:
 * 1. Standard Synchronous PDO
 * 2. rcalicdan/fiber-async (AsyncPDO Facade)
 */

use Rcalicdan\FiberAsync\Api\Async;
use Rcalicdan\FiberAsync\Api\AsyncPDO;
use Rcalicdan\FiberAsync\Api\Promise;
use Rcalicdan\FiberAsync\EventLoop\EventLoop;
use Rcalicdan\FiberAsync\PDO\DatabaseConfigFactory;

require_once 'vendor/autoload.php';

// --- Configuration ---
$mysqlConfig = DatabaseConfigFactory::mysql([
    'host'     => 'localhost',
    'database' => 'yo',
    'username' => 'hey', // Use your actual username
    'password' => '1234', // Use your actual password
    'port'     => 3306,
]);

const POOL_SIZE = 10; // Set the desired pool size for the test
const QUERY_COUNT = 100;
const LATENCY_SECONDS = 0.01; // 10ms

// --- Helper Functions ---
function build_dsn_from_config(array $config): string
{
    return sprintf(
        "mysql:host=%s;port=%d;dbname=%s;charset=%s",
        $config['host'] ?? 'localhost',
        $config['port'] ?? 3306,
        $config['database'] ?? '',
        $config['charset'] ?? 'utf8mb4'
    );
}

function setup_performance_db(PDO $pdo, int $rowCount): void
{
    $pdo->exec("DROP TABLE IF EXISTS perf_test");
    $createSql = "CREATE TABLE perf_test (id INT AUTO_INCREMENT PRIMARY KEY, data TEXT)";
    $pdo->exec($createSql);
    $stmt = $pdo->prepare("INSERT INTO perf_test (data) VALUES (?)");
    for ($i = 0; $i < $rowCount; $i++) {
        $stmt->execute(['data-' . $i]);
    }
}

// =================================================================
// == STEP 1: DEFINE THE PERFORMANCE WORKLOADS
// =================================================================

function run_sync_performance_test(array $dbConfig): array
{
    $dsn = build_dsn_from_config($dbConfig);
    $pdo = new PDO($dsn, $dbConfig['username'] ?? null, $dbConfig['password'] ?? null);
    $stmt = $pdo->prepare("SELECT id, data FROM perf_test WHERE id = ?");

    $startTime = microtime(true);
    $startMemory = memory_get_usage();
    $returnedData = [];

    for ($i = 0; $i < QUERY_COUNT; $i++) {
        $idToFind = rand(1, QUERY_COUNT);
        $stmt->execute([$idToFind]);
        $returnedData[] = $stmt->fetch(PDO::FETCH_ASSOC);
        usleep((int)(LATENCY_SECONDS * 1000000));
    }

    $endTime = microtime(true);
    $endMemory = memory_get_peak_usage();
    return [
        'metrics' => ['time' => $endTime - $startTime, 'memory' => $endMemory - $startMemory],
        'data' => $returnedData,
    ];
}

function run_async_performance_test(array $dbConfig): array
{
    AsyncPDO::init($dbConfig, POOL_SIZE);

    return run(function () {
        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $tasks = [];
        for ($i = 0; $i < QUERY_COUNT; $i++) {
            $tasks[] = AsyncPDO::run(function (PDO $pdo) {
                $idToFind = rand(1, QUERY_COUNT);
                $stmt = $pdo->prepare("SELECT id, data FROM perf_test WHERE id = ?");
                $stmt->execute([$idToFind]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                Async::await(delay(LATENCY_SECONDS));
                return $row;
            });
        }

        $returnedData = Async::await(Promise::all($tasks));

        $endTime = microtime(true);
        $endMemory = memory_get_peak_usage();
        return [
            'metrics' => ['time' => $endTime - $startTime, 'memory' => $endMemory - $startMemory],
            'data' => $returnedData,
        ];
    });
}


try {
    echo "\n=================================================\n";
    echo "  PERFORMANCE TEST: MySQL (PHP)\n";
    echo "  (" . QUERY_COUNT . " queries, " . (LATENCY_SECONDS * 1000) . "ms simulated latency each, Pool Size: " . POOL_SIZE . ")\n";
    echo "=================================================\n";

    // Setup database once for all tests to ensure fairness
    echo "\n-- [SETUP] Preparing database... --\n";
    $setupPdo = new PDO(build_dsn_from_config($mysqlConfig), $mysqlConfig['username'] ?? null, $mysqlConfig['password'] ?? null);
    setup_performance_db($setupPdo, QUERY_COUNT);
    echo "[SETUP] Database is ready.\n";
    unset($setupPdo); // Close setup connection

    // --- Run Synchronous Test ---
    echo "\n-- [SYNC] Running performance test... --\n";
    $syncResult = run_sync_performance_test($mysqlConfig);
    echo "[SYNC] Test complete.\n";

    // --- Run Fiber-Async Test ---
    echo "\n-- [ASYNC Fiber-Async] Running performance test... --\n";
    $asyncFiberResult = run_async_performance_test($mysqlConfig);
    echo "[ASYNC Fiber-Async] Test complete.\n";
    AsyncPDO::reset();
    EventLoop::reset();

    // --- Final Report ---
    echo "\n\n========================================================================================\n";
    echo "                                FINAL PERFORMANCE REPORT                                \n";
    echo "========================================================================================\n";
    echo "| Mode                  | Execution Time      | Peak Memory Usage      | QPS        |\n";
    echo "|-----------------------|---------------------|------------------------|------------|\n";

    $syncTime = $syncResult['metrics']['time'];
    $syncMem = $syncResult['metrics']['memory'];
    printf("| Sync (PDO)            | %-19s | %-22s | %-10s |\n", number_format($syncTime, 4) . ' s', number_format($syncMem / 1024, 2) . ' KB', number_format(QUERY_COUNT / $syncTime, 2));

    $fiberTime = $asyncFiberResult['metrics']['time'];
    $fiberMem = $asyncFiberResult['metrics']['memory'];
    printf("| Async (Fiber-Async)   | %-19s | %-22s | %-10s |\n", number_format($fiberTime, 4) . ' s', number_format($fiberMem / 1024, 2) . ' KB', number_format(QUERY_COUNT / $fiberTime, 2));

    echo "========================================================================================\n\n";

    $fiberImprovement = ($syncTime - $fiberTime) / $syncTime * 100;
    printf("Conclusion: For this I/O-bound workload...\n");
    printf("  - AsyncPDO (Fiber-Async) was %.2f%% faster than standard PDO.\n", $fiberImprovement);
} catch (Throwable $e) {
    echo "\n\n--- A TEST FAILED ---\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " on line " . $e->getLine() . "\n";
    echo $e->getTraceAsString() . "\n";
}
