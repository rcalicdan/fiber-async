<?php

require 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Database\MySQLClient;
use Rcalicdan\FiberAsync\Facades\Async;
use Rcalicdan\FiberAsync\Facades\AsyncLoop;

// --- Main Configuration ---
const NUM_OPERATIONS = 500;
const POOL_SIZE       = 50; // The number of "lanes on our bridge"

// Latency Profiles (ms)
$profiles = [
    'Localhost (0.1ms)'    => 0.1,
    'Same DC (1ms)'        => 1,
    'AZ-cross (5ms)'       => 5,
    'Cloud DB (20ms)'      => 20,
    'Inter-region (100ms)' => 100,
];

const DB_CONFIG = [
    'host'     => '127.0.0.1',
    'port'     => 3309,
    'user'     => 'root',
    'password' => 'Reymart12345',
    'database' => 'yo',
    'debug'    => false,
];

// --- Helper & Reporting Classes ---

class Reporter
{
    private static array $results = [];

    public static function start(string $title): array
    {
        echo "\n--- Testing: {$title} ---\n";

        // Force garbage collection and get clean baseline
        gc_collect_cycles();
        gc_mem_caches();

        $baselineMemory = memory_get_usage(true);
        $baselineUsage = memory_get_usage(false); // actual usage, not system allocation

        return [
            'start_time'      => hrtime(true),
            'baseline_memory' => $baselineMemory,
            'baseline_usage'  => $baselineUsage,
        ];
    }

    public static function finish(string $title, array $metrics): void
    {
        $durationNs = hrtime(true) - $metrics['start_time'];
        $durationMs = $durationNs / 1_000_000;

        // Get both allocation and usage
        $currentMemory = memory_get_usage(true);  // system allocation
        $currentUsage = memory_get_usage(false);  // actual usage

        $actualMemoryUsed = $currentMemory - $metrics['baseline_memory'];
        $actualUsageIncrease = $currentUsage - $metrics['baseline_usage'];

        $peakMemory = memory_get_peak_usage(true);
        $peakUsage = memory_get_peak_usage(false);

        echo sprintf("Duration: %.2f ms\n", $durationMs);
        echo 'System Memory Allocated: ' . self::formatBytes($actualMemoryUsed) . "\n";
        echo 'Actual Memory Used: ' . self::formatBytes($actualUsageIncrease) . "\n";
        echo 'Peak System Memory: ' . self::formatBytes($peakMemory) . "\n";
        echo 'Peak Usage: ' . self::formatBytes($peakUsage) . "\n";
    }


    public static function summary(): void
    {
        echo "\n==============================[ BENCHMARK SUMMARY ]==============================\n";
        printf(
            "| %-45s | %12s | %15s | %15s |\n",
            'Scenario',
            'Duration',
            'Actual Memory',
            'Peak Memory'
        );
        echo str_repeat('-', 95) . "\n";

        foreach (self::$results as $title => $result) {
            printf(
                "| %-45s | %10.2f ms | %13s | %13s |\n",
                $title,
                $result['duration_ms'],
                self::formatBytes($result['actual_memory']),
                self::formatBytes($result['peak_memory_used'])
            );
        }
        echo str_repeat('-', 95) . "\n";
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes < 0) return '0 B';

        $units = ['B', 'KB', 'MB', 'GB'];
        $pow   = $bytes > 0 ? floor(log($bytes, 1024)) : 0;

        return round($bytes / (1024 ** $pow), 2) . ' ' . $units[$pow];
    }
}

// --- Benchmark Runner Class ---

class BenchmarkRunner
{
    public function runPdo(string $title, string $query): void
    {
        $metrics = Reporter::start("PDO Sequential - {$title}");

        $pdo = new PDO(
            'mysql:host=' . DB_CONFIG['host'] . ';port=' . DB_CONFIG['port'] . ';dbname=' . DB_CONFIG['database'],
            DB_CONFIG['user'],
            DB_CONFIG['password']
        );

        for ($i = 0; $i < NUM_OPERATIONS; $i++) {
            $pdo->query($query)->fetchAll();
        }

        Reporter::finish("PDO Sequential - {$title}", $metrics);

        // Clean up connection
        $pdo = null;
    }

    public function runAsyncWithPool(string $title, string $query): void
    {
        $metrics = Reporter::start("Async Concurrent w/ Pool - {$title}");

        AsyncLoop::run(function () use ($query) {
            // 1. Create connection pool
            $pool = [];
            for ($i = 0; $i < POOL_SIZE; $i++) {
                $client = new MySQLClient(DB_CONFIG);
                Async::await($client->connect());
                $pool[] = $client;
            }

            try {
                // 2. Dispatch tasks
                $tasks = [];
                for ($i = 0; $i < NUM_OPERATIONS; $i++) {
                    $clientForTask = $pool[$i % POOL_SIZE];
                    $tasks[]       = $clientForTask->query($query);
                }

                Async::await(Async::all($tasks));
            } finally {
                // 3. Close pool
                foreach ($pool as $client) {
                    Async::await($client->close());
                }
            }
        });

        Reporter::finish("Async Concurrent w/ Pool - {$title}", $metrics);
    }

    // Method to run isolated tests
    public function runIsolatedTest(string $approach, string $label, float $ms): void
    {
        echo "\n========== ISOLATED TEST: {$approach} - {$label} ==========\n";

        // Get clean baseline
        gc_collect_cycles();
        gc_mem_caches();
        $startMemory = memory_get_usage(true);

        $query = sprintf('SELECT SLEEP(%.4f)', $ms / 1000);

        if ($approach === 'PDO') {
            $this->runPdo($label, $query);
        } else {
            $this->runAsyncWithPool($label, $query);
        }

        $endMemory = memory_get_usage(true);
        echo "Memory change: " . Reporter::formatBytes($endMemory - $startMemory) . "\n";

        // Wait and cleanup
        sleep(2);
        gc_collect_cycles();
        gc_mem_caches();
    }
}

// --- Main Execution ---

$runner = new BenchmarkRunner;

echo "Starting memory-accurate benchmark with multiple latency profiles: with " . NUM_OPERATIONS . " operations.\nTesting PDO against Async Mysql with " . POOL_SIZE . " concurrent connections pool.\n";

// Option 1: Run all tests in sequence (original behavior)
echo "\n==== SEQUENTIAL BENCHMARK ====\n";
foreach ($profiles as $label => $ms) {
    $query = sprintf('SELECT SLEEP(%.4f)', $ms / 1000);

    $runner->runPdo($label, $query);
    sleep(1);

    $runner->runAsyncWithPool($label, $query);
    sleep(1);
}

Reporter::summary();

// Option 2: Run isolated tests for specific comparisons
echo "\n\n==== ISOLATED TESTS (More Accurate Memory Measurements) ====\n";

// Test a few key scenarios in isolation
$testScenarios = [
    'Localhost (0.1ms)' => 0.1,
    'Inter-region (100ms)' => 100,
];

foreach ($testScenarios as $label => $ms) {
    $runner->runIsolatedTest('PDO', $label, $ms);
    $runner->runIsolatedTest('Async', $label, $ms);
}

echo "\nBenchmark Complete.\n";
