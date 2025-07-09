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

/**
 * Test 1: Synchronous PDO (Baseline)
 */
function runPdoSynchronous(array $config, int $numQueries)
{
    echo "--- [1] Running Synchronous PDO Test ---\n";
    $start = microtime(true);
    try {
        $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset=utf8mb4";
        $pdo = new PDO($dsn, $config['user'], $config['password']);
        for ($i = 1; $i <= $numQueries; $i++) {
            $pdo->query(TEST_QUERY)->fetchAll();
        }
    } catch (\Throwable $e) {
        echo "PDO Error: " . $e->getMessage() . "\n";
        return;
    }
    $duration = microtime(true) - $start;
    echo sprintf("Completed %d queries synchronously in %.4f seconds.\n", $numQueries, $duration);
}

/**
 * Test 2: Sequential Async
 */
function runAsyncSequential(array $config, int $numQueries)
{
    echo "\n--- [2] Running Sequential Async Test ---\n";
    $start = microtime(true);
    // AsyncLoop::run handles starting and stopping the event loop for a single task.
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
            Async::await($client->close());
        }
    });
    $duration = microtime(true) - $start;
    echo sprintf("Completed %d queries sequentially (async) in %.4f seconds.\n", $numQueries, $duration);
}

/**
 * Test 3: Concurrent Async (The Idiomatic Way using the Library Facade)
 */
function runAsyncConcurrent(array $config, int $numQueries)
{
    echo "\n--- [3] Running Concurrent Async Test (using AsyncLoop::runAll) ---\n";
    $start = microtime(true);

    $tasks = [];
    for ($i = 1; $i <= $numQueries; $i++) {
        $tasks[] = function () use ($config) {
            $client = null;
            try {
                $client = new MySQLClient($config);
                Async::await($client->connect());
                $result = Async::await($client->query(TEST_QUERY));
                return $result;
            } finally {
                Async::await($client->close());
            }
        };
    }

    try {
        $allResults = AsyncLoop::runAll($tasks);
        print_r($allResults);

    } catch (\Throwable $e) {
        echo "Async Concurrent Error: " . $e->getMessage() . "\n";
        echo "In " . $e->getFile() . ":" . $e->getLine() . "\n";
    }

    $duration = microtime(true) - $start;
    echo sprintf("Completed %d queries concurrently in %.4f seconds.\n", $numQueries, $duration);
}

// --- Run the Benchmarks ---
echo "Starting benchmark with " . NUM_QUERIES . " queries.\n";
echo "Each query is: \"" . TEST_QUERY . "\"\n\n";

runPdoSynchronous(DB_CONFIG, NUM_QUERIES);
sleep(1);
runAsyncSequential(DB_CONFIG, NUM_QUERIES);
sleep(1);
runAsyncConcurrent(DB_CONFIG, NUM_QUERIES);
