```php
<?php

// realistic_benchmark.php

require 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Database\MySQLClient;
use Rcalicdan\FiberAsync\Facades\Async;
use Rcalicdan\FiberAsync\Facades\AsyncLoop;

// --- Main Configuration ---
const NUM_OPERATIONS = 500;
const POOL_SIZE       = 100; // The number of "lanes on our bridge"

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
    'password' => 'Reymart1234',
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
        gc_collect_cycles();

        return [
            'start_time'   => hrtime(true),
            'start_memory' => memory_get_usage(),
        ];
    }

    public static function finish(string $title, array $metrics): void
    {
        $durationNs    = hrtime(true) - $metrics['start_time'];
        $durationMs    = $durationNs / 1_000_000;
        $peakMemory    = memory_get_peak_usage(true);

        self::$results[$title] = [
            'duration_ms' => $durationMs,
            'peak_memory' => $peakMemory,
        ];

        echo sprintf("Duration: %.2f ms\n", $durationMs);
        echo 'Peak Memory: ' . self::formatBytes($peakMemory) . "\n";
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
        echo str_repeat('-', 72) . "\n";

        foreach (self::$results as $title => $result) {
            printf(
                "| %-45s | %10.2f ms | %13s |\n",
                $title,
                $result['duration_ms'],
                self::formatBytes($result['peak_memory'])
            );
        }
        echo str_repeat('-', 72) . "\n";
    }

    private static function formatBytes(int $bytes): string
    {
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
        $pdo     = new PDO(
            'mysql:host=' . DB_CONFIG['host'] . ';port=' . DB_CONFIG['port'] . ';dbname=' . DB_CONFIG['database'],
            DB_CONFIG['user'],
            DB_CONFIG['password']
        );

        for ($i = 0; $i < NUM_OPERATIONS; $i++) {
            $pdo->query($query)->fetchAll();
        }

        Reporter::finish("PDO Sequential - {$title}", $metrics);
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
}

// --- Main Execution ---

$runner = new BenchmarkRunner;

echo "Starting realistic benchmark with multiple latency profiles:\n";

foreach ($profiles as $label => $ms) {
    // build the SLEEP query based on milliseconds
    $query = sprintf('SELECT SLEEP(%.4f)', $ms / 1000);

    // Run sequential PDO
    $runner->runPdo($label, $query);
    sleep(1);

    // Run async with pool
    $runner->runAsyncWithPool($label, $query);
    sleep(1);
}

// Output summary
Reporter::summary();

echo "\nBenchmark Complete.\n";

