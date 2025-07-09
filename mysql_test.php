<?php
require 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Database\MySQLClient;
use Rcalicdan\FiberAsync\Facades\AsyncLoop;
use Rcalicdan\FiberAsync\Facades\Async;

// --- Configuration ---
const NUM_QUERIES = 20;
const DB_CONFIG = [
    'host' => '127.0.0.1',
    'port' => 3309,
    'user' => 'root',
    'password' => 'Reymart1234',
    'database' => 'yo',
    'debug' => false,
];
const TEST_QUERY = "SELECT SLEEP(0.1) as result";

function formatBytes(int $bytes, int $precision = 2): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Test 1: Synchronous PDO (The Standard Way)
 */
function runPdoSequential(array $config, int $numQueries)
{
    echo "\n--- [1] Running Sequential PDO Test ---\n";
    $startMemory = memory_get_usage();
    $start = microtime(true);

    $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']}";
    try {
        $pdo = new PDO($dsn, $config['user'], $config['password']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        for ($i = 1; $i <= $numQueries; $i++) {
            $stmt = $pdo->prepare(TEST_QUERY);
            $stmt->execute();
            $stmt->fetchAll();
        }
    } catch (\PDOException $e) {
        echo "PDO Error: " . $e->getMessage() . "\n";
    }
    $pdo = null; // Ensure connection is closed

    $duration = microtime(true) - $start;
    $endMemory = memory_get_usage();
    $peakMemory = memory_get_peak_usage(true);
    echo sprintf("Completed %d queries sequentially (PDO) in %.4f seconds.\n", $numQueries, $duration);
    echo "Memory Used: " . formatBytes($endMemory - $startMemory) . " | Peak Memory: " . formatBytes($peakMemory) . "\n";
}

/**
 * Test 2: Sequential Async (Your Library)
 */
function runAsyncSequential(array $config, int $numQueries)
{
    echo "\n--- [2] Running Sequential Async Test ---\n";
    $startMemory = memory_get_usage();
    $start = microtime(true);

    AsyncLoop::run(function () use ($config, $numQueries) {
        $client = new MySQLClient($config);
        try {
            Async::await($client->connect());
            for ($i = 1; $i <= $numQueries; $i++) {
                Async::await($client->query(TEST_QUERY));
            }
        } catch (\Throwable $e) {
            echo "Async Sequential Error: " . $e->getMessage() . "\n";
        } finally {
            if ($client) {
                Async::await($client->close());
            }
        }
    });

    $duration = microtime(true) - $start;
    $endMemory = memory_get_usage();
    $peakMemory = memory_get_peak_usage(true);
    echo sprintf("Completed %d queries sequentially (async) in %.4f seconds.\n", $numQueries, $duration);
    echo "Memory Used: " . formatBytes($endMemory - $startMemory) . " | Peak Memory: " . formatBytes($peakMemory) . "\n";
}

/**
 * Test 3: Concurrent Async (Your Library's Superpower)
 */
function runAsyncConcurrent(array $config, int $numQueries)
{
    echo "\n--- [3] Running Concurrent Async Test (using AsyncLoop::runAll) ---\n";
    $startMemory = memory_get_usage();
    $start = microtime(true);

    $tasks = [];
    for ($i = 1; $i <= $numQueries; $i++) {
        $tasks[] = function () use ($config) {
            $client = null;
            try {
                $client = new MySQLClient($config);
                Async::await($client->connect());
                return Async::await($client->query(TEST_QUERY));
            } finally {
                if ($client) {
                    Async::await($client->close());
                }
            }
        };
    }
    try {
        AsyncLoop::runAll($tasks);
    } catch (\Throwable $e) {
        echo "Async Concurrent Error: " . $e->getMessage() . "\n";
    }

    $duration = microtime(true) - $start;
    $endMemory = memory_get_usage();
    $peakMemory = memory_get_peak_usage(true);
    echo sprintf("Completed %d queries concurrently in %.4f seconds.\n", $numQueries, $duration);
    echo "Memory Used: " . formatBytes($endMemory - $startMemory) . " | Peak Memory: " . formatBytes($peakMemory) . "\n";
}

// --- Run the Benchmarks ---
echo "Starting benchmark with " . NUM_QUERIES . " queries.\n";
echo "Each query is: \"" . TEST_QUERY . "\"\n";
// Prime memory peak usage before running tests
$prime = str_repeat('a', 1024 * 1024); unset($prime);

runPdoSequential(DB_CONFIG, NUM_QUERIES);
gc_collect_cycles(); // Force garbage collection
sleep(1);

runAsyncSequential(DB_CONFIG, NUM_QUERIES);
gc_collect_cycles();
sleep(1);

runAsyncConcurrent(DB_CONFIG, NUM_QUERIES);
gc_collect_cycles();

echo "\n========================================\n";
echo "Benchmark Complete.\n";