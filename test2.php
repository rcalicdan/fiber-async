<?php

/**
 * Low-Level Performance Benchmark: Cooperative Multitasking vs. Synchronous
 *
 * This script benchmarks:
 * 1. Standard Synchronous PDO (blocking)
 * 2. Hibla's low-level PDOHandler, which uses the EventLoop's single-connection
 *    PDOManager and cooperative multitasking (`usleep`) to achieve concurrency.
 */

use Rcalicdan\FiberAsync\Api\Promise;
use Rcalicdan\FiberAsync\EventLoop\EventLoop;
use Rcalicdan\FiberAsync\PDO\DatabaseConfigFactory;
use Rcalicdan\FiberAsync\PDO\Handlers\PDOHandler; 

require_once 'vendor/autoload.php';

// --- Configuration ---
$mysqlConfig = DatabaseConfigFactory::mysql([
    'host'     => 'localhost',
    'database' => 'yo',
    'username' => 'root', 
    'password' => 'Reymart1234', 
    'port'     => 3309,
]);

const QUERY_COUNT = 500; 
const LATENCY_SECONDS = 0.001; 

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
    $stmt = $pdo->prepare("SELECT id FROM perf_test WHERE id = ?");

    $startTime = microtime(true);
    $startMemory = memory_get_usage();

    for ($i = 0; $i < QUERY_COUNT; $i++) {
        $idToFind = rand(1, QUERY_COUNT);
        $stmt->execute([$idToFind]);
        $stmt->fetch(PDO::FETCH_ASSOC);
        usleep((int)(LATENCY_SECONDS * 1000000));
    }

    $endTime = microtime(true);
    $endMemory = memory_get_peak_usage();
    return [
        'metrics' => ['time' => $endTime - $startTime, 'memory' => $endMemory - $startMemory],
    ];
}

function run_low_level_async_test(array $dbConfig): array
{
    $loop = EventLoop::getInstance();
    $loop->configureDatabase($dbConfig);

    $pdoHandler = new PDOHandler();

    return run(function () use ($pdoHandler) {
        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $tasks = [];
        for ($i = 0; $i < QUERY_COUNT; $i++) {
            $idToFind = rand(1, QUERY_COUNT);
            $tasks[] = $pdoHandler->query(
                "SELECT id FROM perf_test WHERE id = ?",
                [$idToFind],
                ['latency' => LATENCY_SECONDS] // Pass the same latency
            );
        }

        await(Promise::all($tasks));

        $endTime = microtime(true);
        $endMemory = memory_get_peak_usage();
        return [
            'metrics' => ['time' => $endTime - $startTime, 'memory' => $endMemory - $startMemory],
        ];
    });
}

try {
    echo "\n===================================================================\n";
    echo "  LOW-LEVEL BENCHMARK: Cooperative Multitasking vs. Synchronous\n";
    echo "  (" . QUERY_COUNT . " queries, " . (LATENCY_SECONDS * 1000) . "ms simulated latency each, Single Connection)\n";
    echo "===================================================================\n";

    echo "\n-- [SETUP] Preparing database... --\n";
    $setupPdo = new PDO(build_dsn_from_config($mysqlConfig), $mysqlConfig['username'] ?? null, $mysqlConfig['password'] ?? null);
    setup_performance_db($setupPdo, QUERY_COUNT);
    echo "[SETUP] Database is ready.\n";
    unset($setupPdo);

    echo "\n-- [SYNC] Running performance test... --\n";
    $syncResult = run_sync_performance_test($mysqlConfig);
    echo "[SYNC] Test complete.\n";

    echo "\n-- [ASYNC Low-Level Handler] Running performance test... --\n";
    $lowLevelAsyncResult = run_low_level_async_test($mysqlConfig);
    echo "[ASYNC Low-Level Handler] Test complete.\n";
    EventLoop::reset(); 

    echo "\n\n========================================================================================\n";
    echo "                                FINAL PERFORMANCE REPORT                                \n";
    echo "========================================================================================\n";
    echo "| Mode                        | Execution Time      | Peak Memory Usage      | QPS        |\n";
    echo "|-----------------------------|---------------------|------------------------|------------|\n";

    $syncTime = $syncResult['metrics']['time'];
    $syncMem = $syncResult['metrics']['memory'];
    printf("| Sync (PDO)                  | %-19s | %-22s | %-10s |\n", number_format($syncTime, 4) . ' s', number_format($syncMem / 1024, 2) . ' KB', number_format(QUERY_COUNT / $syncTime, 2));

    $asyncTime = $lowLevelAsyncResult['metrics']['time'];
    $asyncMem = $lowLevelAsyncResult['metrics']['memory'];
    printf("| Async (Low-Level Handler)   | %-19s | %-22s | %-10s |\n", number_format($asyncTime, 4) . ' s', number_format($asyncMem / 1024, 2) . ' KB', number_format(QUERY_COUNT / $asyncTime, 2));

    echo "========================================================================================\n\n";

    $improvement = ($syncTime - $asyncTime) / $syncTime * 100;
    printf("Conclusion: For this single-connection workload...\n");
    printf("  - The Low-Level Handler was %.2f%% faster than standard Synchronous PDO.\n", $improvement);

} catch (Throwable $e) {
    echo "\n\n--- A TEST FAILED ---\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " on line " . $e->getLine() . "\n";
    echo $e->getTraceAsString() . "\n";
}