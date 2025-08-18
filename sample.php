<?php
// fiberasync_benchmark_corrected.php

use Rcalicdan\FiberAsync\Api\AsyncPDO;
use Rcalicdan\FiberAsync\PDO\DatabaseConfigFactory;
use Rcalicdan\FiberAsync\Promise\Promise;

require 'vendor/autoload.php';

// Memory tracking function
function getMemoryUsage() {
    return [
        'current' => memory_get_usage(true),
        'peak' => memory_get_peak_usage(true),
        'current_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        'peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2)
    ];
}

echo "=== FiberAsync MySQL Performance Benchmark ===\n\n";

$mysqlConfig = DatabaseConfigFactory::mysql([
    'host'     => 'localhost',
    'database' => 'yo',
    'username' => 'hey',
    'password' => '1234',
    'port'     => 3306,
]);

AsyncPDO::init($mysqlConfig, 10);

echo "Warming up connection pool...\n";
run(function () {
    await(AsyncPDO::query("SELECT 1"));
});
echo "Warmup completed.\n\n";

//===========================================
// TEST 1: Simple Concurrent Queries
//===========================================
echo "TEST 1: Simple Concurrent Queries (5 queries)\n";
echo "------------------------------------------------\n";

run(function () {
    $memoryStart = getMemoryUsage();
    $startTime = microtime(true);

    $promises = [];
    for ($i = 1; $i <= 5; $i++) {
        $promises[] = AsyncPDO::query("SELECT $i as test_id, 'FiberAsync Test' as source")
            ->then(function ($result) use ($i, $startTime) {
                $queryTime = microtime(true) - $startTime;
                echo "FiberAsync Query $i completed: " . number_format($queryTime * 1000, 1) . "ms\n";
                return $result;
            });
    }

    $results = await(Promise::all($promises));
    $endTime = microtime(true);
    $memoryEnd = getMemoryUsage();

    $totalTime = $endTime - $startTime;
    $qps = 5 / $totalTime;

    echo "\nRESULTS:\n";
    echo "- Total Time: " . number_format($totalTime * 1000, 1) . "ms\n";
    echo "- QPS (Queries Per Second): " . number_format($qps, 2) . "\n";
    echo "- Memory Usage: {$memoryEnd['current_mb']}MB (Peak: {$memoryEnd['peak_mb']}MB)\n";
    echo "- Memory Increase: " . ($memoryEnd['current_mb'] - $memoryStart['current_mb']) . "MB\n\n";
});

//===========================================
// TEST 2: High Concurrency Test
//===========================================
echo "TEST 2: High Concurrency Test (20 queries)\n";
echo "--------------------------------------------\n";

run(function () {
    $memoryStart = getMemoryUsage();
    $startTime = microtime(true);

    $promises = [];
    for ($i = 1; $i <= 20; $i++) {
        $promises[] = AsyncPDO::query("SELECT $i as query_id, NOW() as timestamp");
    }

    $results = await(Promise::all($promises));
    $endTime = microtime(true);
    $memoryEnd = getMemoryUsage();

    $totalTime = $endTime - $startTime;
    $qps = 20 / $totalTime;

    echo "RESULTS:\n";
    echo "- Total Time: " . number_format($totalTime * 1000, 1) . "ms\n";
    echo "- QPS (Queries Per Second): " . number_format($qps, 2) . "\n";
    echo "- Memory Usage: {$memoryEnd['current_mb']}MB (Peak: {$memoryEnd['peak_mb']}MB)\n";
    echo "- Memory Increase: " . ($memoryEnd['current_mb'] - $memoryStart['current_mb']) . "MB\n\n";
});

//===========================================
// TEST 3: Transaction Test
//===========================================
echo "TEST 3: Concurrent Transactions (4 transactions)\n";
echo "-------------------------------------------------\n";

run(function () {
    // Setup test table
    await(AsyncPDO::execute("CREATE TABLE IF NOT EXISTS fiberasync_test (
        id INT AUTO_INCREMENT PRIMARY KEY,
        batch_id VARCHAR(50),
        name VARCHAR(100),
        value INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )"));
    await(AsyncPDO::execute("TRUNCATE TABLE fiberasync_test"));

    $memoryStart = getMemoryUsage();
    $startTime = microtime(true);

    $promises = [];
    for ($i = 1; $i <= 4; $i++) {
        $promises[] = AsyncPDO::transaction(function ($pdo) use ($i, $startTime) {
            $transactionStart = microtime(true) - $startTime;
            echo "FiberAsync Transaction $i START: " . number_format($transactionStart * 1000, 1) . "ms\n";
            
            for ($j = 1; $j <= 3; $j++) {
                $stmt = $pdo->prepare("INSERT INTO fiberasync_test (batch_id, name, value) VALUES (?, ?, ?)");
                $stmt->execute(["batch-$i", "FiberAsync-Record-$i-$j", $i * 100 + $j]);
            }
            
            // Use your library's built-in delay function!
            delay(0.2); // 200ms delay
            
            $transactionEnd = microtime(true) - $startTime;
            echo "FiberAsync Transaction $i END: " . number_format($transactionEnd * 1000, 1) . "ms\n";
            
            return $i;
        });
    }

    $results = await(Promise::all($promises));
    $endTime = microtime(true);
    $memoryEnd = getMemoryUsage();

    $totalTime = $endTime - $startTime;
    $tps = 4 / $totalTime;

    echo "\nRESULTS:\n";
    echo "- Total Time: " . number_format($totalTime * 1000, 1) . "ms\n";
    echo "- TPS (Transactions Per Second): " . number_format($tps, 2) . "\n";
    echo "- Memory Usage: {$memoryEnd['current_mb']}MB (Peak: {$memoryEnd['peak_mb']}MB)\n";
    echo "- Memory Increase: " . ($memoryEnd['current_mb'] - $memoryStart['current_mb']) . "MB\n\n";

    // Verify transaction results
    $countResult = await(AsyncPDO::fetchValue("SELECT COUNT(*) FROM fiberasync_test"));
    echo "- Records Inserted: $countResult\n\n";
});

//===========================================
// TEST 4: Stress Test with Delays
//===========================================
echo "TEST 4: Stress Test with Processing Delays (10 queries)\n";
echo "--------------------------------------------------------\n";

run(function () {
    $memoryStart = getMemoryUsage();
    $startTime = microtime(true);

    $delays = [100, 200, 300, 150, 250, 180, 120, 350, 80, 400]; 
    $promises = [];

    for ($i = 1; $i <= 10; $i++) {
        $delayMs = $delays[$i - 1];
        $promises[] = AsyncPDO::query("SELECT $i as query_id, '$delayMs' as delay_ms")
            ->then(function ($result) use ($i, $delayMs, $startTime) {
                $queryStart = microtime(true) - $startTime;
                echo "FiberAsync Stress Query $i START: " . number_format($queryStart * 1000, 1) . "ms (delay: {$delayMs}ms)\n";
                
                delay($delayMs / 1000.0); 
                
                $queryEnd = microtime(true) - $startTime;
                echo "FiberAsync Stress Query $i END: " . number_format($queryEnd * 1000, 1) . "ms\n";
                
                return $result;
            });
    }

    $results = await(Promise::all($promises));
    $endTime = microtime(true);
    $memoryEnd = getMemoryUsage();

    $totalTime = $endTime - $startTime;
    $qps = 10 / $totalTime;

    echo "\nRESULTS:\n";
    echo "- Total Time: " . number_format($totalTime * 1000, 1) . "ms\n";
    echo "- QPS (Queries Per Second): " . number_format($qps, 2) . "\n";
    echo "- Memory Usage: {$memoryEnd['current_mb']}MB (Peak: {$memoryEnd['peak_mb']}MB)\n";
    echo "- Memory Increase: " . ($memoryEnd['current_mb'] - $memoryStart['current_mb']) . "MB\n\n";

    echo "Expected total delay time (sequential): " . array_sum($delays) . "ms\n";
    echo "Actual concurrent time should be ~" . max($delays) . "ms if truly concurrent\n";
});

AsyncPDO::reset();

echo "=== FiberAsync MySQL Benchmark Complete ===\n";