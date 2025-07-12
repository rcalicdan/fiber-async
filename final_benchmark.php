<?php

// realistic_benchmark.php

require 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Database\MySQLClient;
use Rcalicdan\FiberAsync\Facades\Async;
use Rcalicdan\FiberAsync\Facades\AsyncLoop;

// --- Main Configuration ---

const NUM_OPERATIONS = 50; // A good number to see the effects of concurrency
const DB_CONFIG = [
    'host' => '127.0.0.1',
    'port' => 3309,
    'user' => 'root',
    'password' => 'Reymart1234',
    'database' => 'yo',
    'debug' => false,
];

// --- Helper & Reporting Classes ---

class Reporter
{
    private static array $results = [];

    public static function start(string $title): array
    {
        echo "\n--- Testing: {$title} ---\n";
        gc_collect_cycles();

        return [
            'start_time' => hrtime(true),
            'start_memory' => memory_get_usage(),
        ];
    }

    public static function finish(string $title, array $metrics): void
    {
        $durationNs = hrtime(true) - $metrics['start_time'];
        $durationMs = $durationNs / 1_000_000;
        $peakMemory = memory_get_peak_usage(true);

        self::$results[$title] = [
            'duration_ms' => $durationMs,
            'peak_memory' => $peakMemory,
        ];

        echo sprintf("Duration: %.2f ms\n", $durationMs);
        echo 'Peak Memory: '.self::formatBytes($peakMemory)."\n";
    }

    public static function summary(): void
    {
        echo "\n==============================[ BENCHMARK SUMMARY ]==============================\n";
        printf(
            "| %-45s | %12s | %15s |\n",
            'Scenario',
            'Duration',
            'Peak Memory'
        );
        echo str_repeat('-', 72)."\n";

        foreach (self::$results as $title => $result) {
            // Add a separator between different latency profiles
            if (str_contains($title, 'PDO -')) {
                echo str_repeat('-', 72)."\n";
            }
            printf(
                "| %-45s | %10.2f ms | %13s |\n",
                $title,
                $result['duration_ms'],
                self::formatBytes($result['peak_memory'])
            );
        }
        echo str_repeat('-', 72)."\n";
    }

    private static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $pow = $bytes > 0 ? floor(log($bytes, 1024)) : 0;

        return round($bytes / (1024 ** $pow), 2).' '.$units[$pow];
    }
}

// --- Benchmark Runner Class ---

class BenchmarkRunner
{
    public function runPdo(string $title, string $query): void
    {
        $metrics = Reporter::start("PDO - {$title}");
        $pdo = new PDO('mysql:host='.DB_CONFIG['host'].';port='.DB_CONFIG['port'].';dbname='.DB_CONFIG['database'], DB_CONFIG['user'], DB_CONFIG['password']);
        for ($i = 0; $i < NUM_OPERATIONS; $i++) {
            $pdo->query($query)->fetchAll();
        }
        Reporter::finish("PDO - {$title}", $metrics);
    }

    public function runAsync(string $title, string $query): void
    {
        $metrics = Reporter::start("Async Concurrent - {$title}");
        AsyncLoop::run(function () use ($query) {
            // This simulates connection reuse / pooling, which is crucial for a fair comparison.
            $client = new MySQLClient(DB_CONFIG);

            try {
                Async::await($client->connect());
                $tasks = [];
                for ($i = 0; $i < NUM_OPERATIONS; $i++) {
                    $tasks[] = $client->query($query);
                }
                Async::await(Async::all($tasks));
            } finally {
                if ($client) {
                    Async::await($client->close());
                }
            }
        });
        Reporter::finish("Async Concurrent - {$title}", $metrics);
    }
}

// --- Main Execution ---

$scenarios = [
    'CPU-Bound (Fast Local Query, ~1ms)' => 'SELECT 1',
    'Typical DB Latency (50ms)' => 'SELECT SLEEP(0.05)',
    'Slow API Latency (200ms)' => 'SELECT SLEEP(0.2)',
];

$runner = new BenchmarkRunner;

echo 'Starting realistic latency benchmark with '.NUM_OPERATIONS." operations per scenario.\n";

foreach ($scenarios as $title => $query) {
    $runner->runPdo($title, $query);
    sleep(1);
    $runner->runAsync($title, $query);
}

Reporter::summary();

echo "\nBenchmark Complete.\n";
