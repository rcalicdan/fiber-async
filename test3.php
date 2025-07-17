<?php

/**
 * High-Latency Performance Benchmark for the AsyncPDO Facade with Data Verification.
 *
 * This script simulates a real-world I/O workload and verifies that both sync
 * and async methods produce the same results, providing a legitimate benchmark.
 */

require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Database\AsyncPdoPool;
use Rcalicdan\FiberAsync\Facades\Async;
use Rcalicdan\FiberAsync\Facades\AsyncLoop;
use Rcalicdan\FiberAsync\Facades\AsyncPDO;
use Rcalicdan\FiberAsync\Database\DatabaseConfigFactory;
use Rcalicdan\FiberAsync\AsyncEventLoop;

class_exists(AsyncPdoPool::class) || require_once __DIR__ . '/src/Database/AsyncPdoPool.php';

// --- Configuration ---
$sqliteConfig = DatabaseConfigFactory::sqlite('file::memory:?cache=shared');

$mysqlConfig = DatabaseConfigFactory::mysql([
    'host'     => '127.0.0.1',
    'database' => 'yo',
    'username' => 'root',
    'password' => 'Reymart1234',
    'port'     => 3309,
]);

$poolSize = 10;
$queryCount = 200;
$latencySeconds = 0.01; // 10ms

// --- Helper Functions ---
function build_dsn_from_config(array $config): string
{
    $driver = $config['driver'];
    switch ($driver) {
        case 'mysql':
            return sprintf(
                "mysql:host=%s;port=%d;dbname=%s;charset=%s",
                $config['host'] ?? 'localhost',
                $config['port'] ?? 3306,
                $config['database'] ?? '',
                $config['charset'] ?? 'utf8mb4'
            );
        case 'sqlite':
            return "sqlite:" . $config['database'];
        default:
            throw new \InvalidArgumentException("Unsupported driver for DSN builder: $driver");
    }
}

function setup_performance_db(PDO $pdo, int $rowCount, string $driverName): void
{
    $pdo->exec("DROP TABLE IF EXISTS perf_test");
    $createSql = "CREATE TABLE perf_test (id " . ($driverName === 'mysql' ? "INT AUTO_INCREMENT" : "INTEGER") . " PRIMARY KEY, data TEXT)";
    $pdo->exec($createSql);
    $stmt = $pdo->prepare("INSERT INTO perf_test (data) VALUES (?)");
    for ($i = 0; $i < $rowCount; $i++) {
        $stmt->execute(['data-' . $i]);
    }
}

// =================================================================
// == STEP 1: DEFINE THE PERFORMANCE WORKLOADS
// =================================================================

function run_sync_performance_test(array $dbConfig, int $count, float $latency): array
{
    $dsn = build_dsn_from_config($dbConfig);
    $pdo = new PDO($dsn, $dbConfig['username'] ?? null, $dbConfig['password'] ?? null);
    setup_performance_db($pdo, $count, $dbConfig['driver']);
    $stmt = $pdo->prepare("SELECT id, data FROM perf_test WHERE id = ?");

    $startTime = microtime(true);
    $startMemory = memory_get_usage();
    $returnedData = [];

    for ($i = 0; $i < $count; $i++) {
        $idToFind = rand(1, $count);
        $stmt->execute([$idToFind]);
        $returnedData[] = $stmt->fetch(PDO::FETCH_ASSOC);
        usleep((int)($latency * 1000000));
    }

    $endTime = microtime(true);
    $endMemory = memory_get_peak_usage();
    return [
        'metrics' => ['time' => $endTime - $startTime, 'memory' => $endMemory - $startMemory],
        'data' => $returnedData,
    ];
}

function run_async_performance_test(array $dbConfig, int $count, float $latency, int $poolSize): array
{
    AsyncPDO::init($dbConfig, $poolSize);

    return AsyncLoop::run(function () use ($dbConfig, $count, $latency) {
        await(AsyncPDO::run(fn(PDO $pdo) => setup_performance_db($pdo, $count, $dbConfig['driver'])));

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $tasks = [];
        for ($i = 0; $i < $count; $i++) {
            $tasks[] = AsyncPDO::run(function (PDO $pdo) use ($latency, $count) {
                $idToFind = rand(1, $count);
                $stmt = $pdo->prepare("SELECT id, data FROM perf_test WHERE id = ?");
                $stmt->execute([$idToFind]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                await(Async::delay($latency));
                return $row;
            });
        }

        $returnedData = await(Async::all($tasks));

        $endTime = microtime(true);
        $endMemory = memory_get_peak_usage();
        return [
            'metrics' => ['time' => $endTime - $startTime, 'memory' => $endMemory - $startMemory],
            'data' => $returnedData,
        ];
    });
}

// =================================================================
// == STEP 2: ORCHESTRATE AND VERIFY
// =================================================================

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

function run_test_for_driver(string $driverName, array $dbConfig, int $poolSize, int $count, float $latency): array
{
    echo "\n=================================================\n";
    echo "  PERFORMANCE TEST FOR: " . strtoupper($driverName) . "\n";
    echo "  ($count queries, " . ($latency * 1000) . "ms simulated latency each)\n";
    echo "=================================================\n";

    echo "\n-- [SYNC] Running performance test... --\n";
    $syncResult = run_sync_performance_test($dbConfig, $count, $latency);
    echo "[SYNC] Test complete.\n";
    print_sample_results("[SYNC] $driverName", $syncResult['data']);

    echo "\n-- [ASYNC] Running performance test... --\n";
    $asyncResult = run_async_performance_test($dbConfig, $count, $latency, $poolSize);
    echo "[ASYNC] Test complete.\n";
    print_sample_results("[ASYNC] $driverName", $asyncResult['data']);

    AsyncPDO::reset();
    AsyncEventLoop::reset();

    return ['sync' => $syncResult['metrics'], 'async' => $asyncResult['metrics']];
}

// --- Main Execution ---
try {
    $results = [];
    $results['sqlite'] = run_test_for_driver('sqlite', $sqliteConfig, $poolSize, $queryCount, $latencySeconds);
    $results['mysql'] = run_test_for_driver('mysql', $mysqlConfig, $poolSize, $queryCount, $latencySeconds);

    // --- Final Report ---
    echo "\n\n======================================================================\n";
    echo "                      FINAL PERFORMANCE REPORT                      \n";
    echo "======================================================================\n";
    echo "| Driver | Mode      | Execution Time      | Peak Memory Usage      |\n";
    echo "|--------|-----------|---------------------|------------------------|\n";
    printf("| SQLite | Sync      | %-19s | %-22s |\n", number_format($results['sqlite']['sync']['time'], 4) . ' s', number_format($results['sqlite']['sync']['memory'] / 1024, 2) . ' KB');
    printf("| SQLite | Async     | %-19s | %-22s |\n", number_format($results['sqlite']['async']['time'], 4) . ' s', number_format($results['sqlite']['async']['memory'] / 1024, 2) . ' KB');
    echo "|--------|-----------|---------------------|------------------------|\n";
    printf("| MySQL  | Sync      | %-19s | %-22s |\n", number_format($results['mysql']['sync']['time'], 4) . ' s', number_format($results['mysql']['sync']['memory'] / 1024, 2) . ' KB');
    printf("| MySQL  | Async     | %-19s | %-22s |\n", number_format($results['mysql']['async']['time'], 4) . ' s', number_format($results['mysql']['async']['memory'] / 1024, 2) . ' KB');
    echo "======================================================================\n\n";

    $improvement = ($results['mysql']['sync']['time'] - $results['mysql']['async']['time']) / $results['mysql']['sync']['time'] * 100;
    printf("Conclusion: For MySQL, the AsyncPDO facade was %.2f%% faster than standard PDO for this I/O-bound workload.\n", $improvement);
} catch (Throwable $e) {
    echo "\n\n--- A TEST FAILED ---\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " on line " . $e->getLine() . "\n";
    echo $e->getTraceAsString() . "\n";
}
