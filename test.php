<?php

use Rcalicdan\FiberAsync\Api\AsyncMySQL;
use Rcalicdan\FiberAsync\Api\AsyncPDO;

require "vendor/autoload.php";

const POOL_SIZE = 50;

function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    
    for ($i = 0; $bytes > 1024; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}

function runBenchmark($name, $client, $queryCount, $poolSize) {
    $poolSize = POOL_SIZE;
    echo "\n=== $name - $queryCount queries - $poolSize pool size ===\n";
    
    $memoryBefore = memory_get_usage();
    $peakMemoryTracker = $memoryBefore; 
    
    $startTime = microtime(true);
    
    run(function () use ($client, $queryCount, &$peakMemoryTracker) {
        if ($client === 'AsyncMySQL') {
            AsyncMySQL::init([
                "driver" => "mysql",
                'host'     => 'localhost',
                'database' => 'yo',
                'username' => 'hey',
                'password' => '1234',
                'port'     => 3306,
            ], POOL_SIZE);
        } else {
            AsyncPDO::init([
                "driver" => "mysql",
                'host'     => 'localhost',
                'database' => 'yo',
                'username' => 'hey',
                'password' => '1234',
                'port'     => 3306,
            ], POOL_SIZE);
        }
        
        // Track peak after initialization
        $currentMemory = memory_get_usage();
        if ($currentMemory > $peakMemoryTracker) {
            $peakMemoryTracker = $currentMemory;
        }
        
        $queries = [];
        for ($i = 0; $i < $queryCount; $i++) {
            $queries[] = function () use ($client, &$peakMemoryTracker) {
                if ($client === 'AsyncMySQL') {
                    AsyncMySQL::query('SELECT * FROM users WHERE id = 1');
                } else {
                    AsyncPDO::query('SELECT * FROM users WHERE id = 1');
                }
                await(delay(0.001));
                
                // Track peak memory during each query
                $currentMemory = memory_get_usage();
                if ($currentMemory > $peakMemoryTracker) {
                    $peakMemoryTracker = $currentMemory;
                }
            };
        }
        
        await(all($queries));
        
        // Final peak check after all queries
        $currentMemory = memory_get_usage();
        if ($currentMemory > $peakMemoryTracker) {
            $peakMemoryTracker = $currentMemory;
        }
    });
    
    $endTime = microtime(true);
    $totalTime = $endTime - $startTime;
    $qps = $queryCount / $totalTime;
    
    $memoryAfter = memory_get_usage();
    $memoryUsed = $memoryAfter - $memoryBefore;
    $peakMemoryUsed = $peakMemoryTracker - $memoryBefore;
    
    return [
        'time' => $totalTime,
        'qps' => $qps,
        'memory_used' => $memoryUsed,
        'peak_memory_used' => $peakMemoryUsed,
        'final_memory' => $memoryAfter,
        'final_peak_memory' => $peakMemoryTracker
    ];
}

function runMultipleRounds($name, $client, $queryCount, $poolSize, $rounds = 3) {
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "BENCHMARKING $name - $queryCount QUERIES ($rounds rounds)\n";
    echo str_repeat("=", 60) . "\n";
    
    $results = [];
    
    for ($round = 1; $round <= $rounds; $round++) {
        echo "\nRound $round/$rounds:\n";
        gc_collect_cycles(); // Force garbage collection before each round
        
        $result = runBenchmark($name, $client, $queryCount, $poolSize);
        $results[] = $result;
        
        printf("Time: %.4f seconds\n", $result['time']);
        printf("QPS: %.2f queries/second\n", $result['qps']);
        printf("Memory used: %s\n", formatBytes($result['memory_used']));
        printf("Peak memory used: %s\n", formatBytes($result['peak_memory_used']));
        printf("Final memory: %s\n", formatBytes($result['final_memory']));
        printf("Final peak memory: %s\n", formatBytes($result['final_peak_memory']));
        
        // Small delay between rounds
        sleep(1);
    }
    
    // Calculate averages
    $avgTime = array_sum(array_column($results, 'time')) / $rounds;
    $avgQps = array_sum(array_column($results, 'qps')) / $rounds;
    $avgMemory = array_sum(array_column($results, 'memory_used')) / $rounds;
    $avgPeakMemory = array_sum(array_column($results, 'peak_memory_used')) / $rounds;
    
    echo "\n" . str_repeat("-", 40) . "\n";
    echo "AVERAGE RESULTS ($rounds rounds):\n";
    echo str_repeat("-", 40) . "\n";
    printf("Average Time: %.4f seconds\n", $avgTime);
    printf("Average QPS: %.2f queries/second\n", $avgQps);
    printf("Average Memory: %s\n", formatBytes($avgMemory));
    printf("Average Peak Memory: %s\n", formatBytes($avgPeakMemory));
    
    return [
        'avg_time' => $avgTime,
        'avg_qps' => $avgQps,
        'avg_memory' => $avgMemory,
        'avg_peak_memory' => $avgPeakMemory,
        'all_results' => $results
    ];
}

// Test configurations
$testCounts = [500, 1000, 3000];
$rounds = 5;

echo "PHP Async MySQL Client Benchmark\n";
echo "================================\n";
echo "Testing with query counts: " . implode(", ", $testCounts) . "\n";
echo "Rounds per test: $rounds\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n";

$allResults = [];

// Test AsyncMySQL
foreach ($testCounts as $count) {
    $results = runMultipleRounds('AsyncMySQL', 'AsyncMySQL', $count, POOL_SIZE, $rounds);
    $allResults['AsyncMySQL'][$count] = $results;
}

// Test AsyncPDO
foreach ($testCounts as $count) {
    $results = runMultipleRounds('AsyncPDO', 'AsyncPDO', $count, POOL_SIZE, $rounds);
    $allResults['AsyncPDO'][$count] = $results;
}

// Final comparison summary
echo "\n" . str_repeat("=", 80) . "\n";
echo "FINAL COMPARISON SUMMARY\n";
echo str_repeat("=", 80) . "\n";

printf("%-15s %-10s %-15s %-15s %-15s %-15s\n", 
    "Client", "Queries", "Avg Time (s)", "Avg QPS", "Avg Memory", "Avg Peak Mem");
echo str_repeat("-", 80) . "\n";

foreach (['AsyncMySQL', 'AsyncPDO'] as $client) {
    foreach ($testCounts as $count) {
        $result = $allResults[$client][$count];
        printf("%-15s %-10d %-15.4f %-15.2f %-15s %-15s\n",
            $client,
            $count,
            $result['avg_time'],
            $result['avg_qps'],
            formatBytes($result['avg_memory']),
            formatBytes($result['avg_peak_memory'])
        );
    }
}

echo "\nBenchmark completed at: " . date('Y-m-d H:i:s') . "\n";