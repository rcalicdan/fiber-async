<?php

require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\AsyncEventLoop;
use Rcalicdan\FiberAsync\Database\AsyncPDOClient;

/**
 * A benchmark to confirm that AsyncPDOClient provides non-blocking,
 * cooperative concurrency, even when including connection overhead in each test.
 * This directly compares a full "request cycle" against traditional PDO.
 */
class FinalPdoBenchmark
{
    // --- IMPORTANT: CONFIGURE YOUR DATABASE HERE ---
    private array $dbConfig = [
        'dsn' => 'mysql:host=127.0.0.1;port=3309;dbname=yo',
        'username' => 'root',
        'password' => 'Reymart1234', // <-- CHANGE THIS
        'options' => [
            \PDO::ATTR_PERSISTENT => false,
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ],
    ];
    // ---------------------------------------------

    private int $numOperations = 50; // Number of operations to run per test

    public function __construct()
    {
        // Ensure the test table exists and is empty
        echo "Initializing test database...\n";
        run(async(function () {
            try {
                $client = new AsyncPDOClient();
                await($client->connect($this->dbConfig));
                await($client->query("CREATE TABLE IF NOT EXISTS final_coop_test (id INT AUTO_INCREMENT PRIMARY KEY, value VARCHAR(255))"));
                await($client->query("TRUNCATE TABLE final_coop_test"));
                await($client->close());
                echo "Database ready.\n";
            } catch (\Exception $e) {
                echo "\nFATAL ERROR: Could not connect to the database or create the test table.\n";
                echo "Please check your credentials and ensure the MySQL server is running on port 3309.\n";
                echo "Error: " . $e->getMessage() . "\n";
                exit(1);
            }
        }));
    }

    public function run()
    {
        $scenarios = [
            'Local DB (Low Latency)' => [
                'connect' => 2, 'query' => 1, 'prepare' => 1, 'execute' => 2, 'fetch' => 0, 'transaction' => 1, 'close' => 1,
            ],
            'High Latency WAN DB (50ms RTT)' => [
                'connect' => 50, 'query' => 25, 'prepare' => 25, 'execute' => 25, 'fetch' => 5, 'transaction' => 25, 'close' => 25,
            ],
        ];

        foreach ($scenarios as $name => $latency) {
            echo "\n🔗 Testing Scenario: {$name}\n";
            echo "   (Simulated Latency per op: Connect: {$latency['connect']}ms, Execute: {$latency['execute']}ms)\n";
            echo str_repeat('=', 70) . "\n";

            // Set the simulated latency for the async handler
            AsyncEventLoop::getInstance()->setPDOLatencyConfig($latency);

            // --- Run Benchmarks ---
            $syncTime = $this->benchmarkSync($latency);
            $this->printResults("Sync PDO (Baseline)", $syncTime, $syncTime);

            $sequentialAsyncTime = $this->benchmarkSequentialAsync();
            $this->printResults("Async Sequential (await in loop)", $sequentialAsyncTime, $syncTime);

            $concurrentAsyncTime = $this->benchmarkConcurrentAsync();
            $this->printResults("Async Concurrent (cooperative)", $concurrentAsyncTime, $syncTime);
        }
    }

    /**
     * Baseline test using standard, blocking PDO. Connects, runs, and closes.
     */
    private function benchmarkSync(array $latency): float
    {
        $start = microtime(true);

        $pdo = new PDO($this->dbConfig['dsn'], $this->dbConfig['username'], $this->dbConfig['password'], $this->dbConfig['options']);
        usleep($latency['connect'] * 1000);

        $stmt = $pdo->prepare("INSERT INTO final_coop_test (value) VALUES (?)");
        usleep($latency['prepare'] * 1000);

        for ($i = 0; $i < $this->numOperations; $i++) {
            $stmt->execute(["sync_val_{$i}"]);
            usleep($latency['execute'] * 1000); // Simulate network round-trip for each operation
        }

        usleep($latency['close'] * 1000);
        $pdo = null; // Close connection

        return (microtime(true) - $start) * 1000;
    }

    /**
     * Tests async client sequentially. This should NOT be fast. Connects, runs, and closes.
     */
    private function benchmarkSequentialAsync(): float
    {
        $start = microtime(true);
        run(async(function () {
            // Connect, prepare, execute, and close all within the timed async block
            $client = new AsyncPDOClient();
            await($client->connect($this->dbConfig));
            $statement = await($client->prepare("INSERT INTO final_coop_test (value) VALUES (?)"));

            // Awaiting inside the loop makes it sequential, defeating the purpose of concurrency.
            for ($i = 0; $i < $this->numOperations; $i++) {
                await($statement->execute(["async_seq_val_{$i}"]));
            }

            await($statement->closeCursor());
            await($client->close());
        }));
        return (microtime(true) - $start) * 1000;
    }

    /**
     * Tests async client concurrently. This SHOULD be fast. Connects, runs, and closes.
     */
    private function benchmarkConcurrentAsync(): float
    {
        $start = microtime(true);
        run(async(function () {
            // Connect, prepare, execute, and close all within the timed async block
            $client = new AsyncPDOClient();
            await($client->connect($this->dbConfig));
            $statement = await($client->prepare("INSERT INTO final_coop_test (value) VALUES (?)"));

            $tasks = [];
            for ($i = 0; $i < $this->numOperations; $i++) {
                $tasks[] = async(fn() => $statement->execute(["async_concurrent_val_{$i}"]));
            }

            // Await all the promises at once. The event loop will run them cooperatively.
            await(concurrent($tasks, $this->numOperations));

            await($statement->closeCursor());
            await($client->close());
        }));
        return (microtime(true) - $start) * 1000;
    }

    private function printResults(string $testName, float $time, float $baseline): void
    {
        $improvement = $baseline > 0 ? (($baseline - $time) / $baseline) * 100 : 0;
        $speedRatio = $time > 0.1 ? $baseline / $time : 0;

        printf(
            "  %-32s | %9.1fms | 🚀 %5.1f%% faster (%.2fx)\n",
            $testName,
            $time,
            max(0, $improvement),
            $speedRatio
        );
    }

    public function __destruct()
    {
        // Cleanup the test table
        try {
            $pdo = new PDO($this->dbConfig['dsn'], $this->dbConfig['username'], $this->dbConfig['password']);
            $pdo->exec("DROP TABLE IF EXISTS final_coop_test");
        } catch (\PDOException $e) {
            // ignore cleanup errors
        }
    }
}

// Run the benchmark
$benchmark = new FinalPdoBenchmark();
$benchmark->run();