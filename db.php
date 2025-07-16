<?php

require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\AsyncEventLoop;
use Rcalicdan\FiberAsync\Database\AsyncPDOClient;
use Rcalicdan\FiberAsync\Facades\Async;

// Define PDOSuccess if not already defined elsewhere
if (!class_exists('PDOSuccess')) {
    class PDOSuccess {}
}

class RealisticPDODriverBenchmark
{
    private array $dbConfig = [
        'dsn' => 'mysql:host=127.0.0.1;port=3309;dbname=yo',
        'username' => 'root',
        'password' => 'Reymart1234',
        'options' => [\PDO::ATTR_PERSISTENT => false],
    ];

    private int $numClientsInPool = 10; // Number of concurrent connections to simulate

    public function __construct()
    {
        // Ensure you have a 'test_db' and 'test_user' with 'test_password' in your MySQL
        // Or adjust config accordingly.
        run(Async::async(function () {
            $client = new AsyncPDOClient();
            await($client->connect($this->dbConfig));
            await($client->query("CREATE TABLE IF NOT EXISTS async_test (id INT AUTO_INCREMENT PRIMARY KEY, value VARCHAR(255), created_at DATETIME DEFAULT CURRENT_TIMESTAMP)"));
            await($client->query("TRUNCATE TABLE async_test"));
            await($client->close());
        })());
    }

    public function runBenchmark()
    {
        $scenarios = [
            'Local DB (Low Latency)' => [
                'connect' => 0,
                'query' => 0,
                'prepare' => 0,
                'execute' => 0,
                'fetch' => 0,
                'transaction' => 0,
                'overhead' => 0, // Simulate very fast network/DB
            ],
            'Fast LAN DB (Moderate Latency)' => [
                'connect' => 5,
                'query' => 2,
                'prepare' => 1,
                'execute' => 3,
                'fetch' => 1,
                'transaction' => 2,
                'overhead' => 1,
            ],
            'WAN DB (High Latency)' => [
                'connect' => 50,
                'query' => 20,
                'prepare' => 10,
                'execute' => 25,
                'fetch' => 5,
                'transaction' => 15,
                'overhead' => 5,
            ],
            'Very High Latency DB' => [
                'connect' => 200,
                'query' => 80,
                'prepare' => 40,
                'execute' => 100,
                'fetch' => 20,
                'transaction' => 60,
                'overhead' => 20,
            ],
        ];

        $numOps = 50; // Total number of operations per test

        foreach ($scenarios as $name => $latency) {
            echo "\n\n🔗 Testing Scenario: {$name}\n";
            echo "   (Simulated Latency per op: Query: {$latency['query']}ms, Execute: {$latency['execute']}ms, Connect: {$latency['connect']}ms)\n";
            echo str_repeat('=', 70) . "\n";
            AsyncEventLoop::getInstance()->setPDOLatencyConfig($latency); // Set latency for the async handler

            $this->printResults('Sequential Query', $this->benchmarkSyncQuery($numOps, $latency), $this->benchmarkAsyncSequentialQuery($numOps));
            $this->printResults('Concurrent Query (Pool)', $this->benchmarkSyncQuery($numOps, $latency), $this->benchmarkAsyncConcurrentQueryWithPool($numOps));
            $this->printResults('Concurrent Prepared (Pool)', $this->benchmarkSyncPrepared($numOps, $latency), $this->benchmarkAsyncConcurrentPreparedWithPool($numOps));
        }
    }

    /**
     * Helper to create and connect a pool of AsyncPDOClient instances.
     */
    private function createClientPool(): array
    {
        $clients = [];
        for ($i = 0; $i < $this->numClientsInPool; $i++) {
            $clients[] = new AsyncPDOClient();
        }

        run(Async::async(function () use ($clients) {
            $connectTasks = [];
            foreach ($clients as $client) {
                $connectTasks[] = Async::async(function () use ($client) {
                    await($client->connect($this->dbConfig));
                });
            }
            // Connect all clients concurrently
            await(concurrent($connectTasks, $this->numClientsInPool));
        })());

        return $clients;
    }

    /**
     * Helper to close a pool of AsyncPDOClient instances.
     */
    private function closeClientPool(array $clients): void
    {
        run(Async::async(function () use ($clients) {
            $closeTasks = [];
            foreach ($clients as $client) {
                $closeTasks[] = Async::async(function () use ($client) {
                    await($client->close());
                });
            }
            await(concurrent($closeTasks, $this->numClientsInPool));
        })());
    }

    // --- Synchronous Methods (remain mostly the same for baseline) ---

    private function benchmarkSyncQuery(int $numOps, array $latency): float
    {
        $start = microtime(true);
        $pdo = new PDO($this->dbConfig['dsn'], $this->dbConfig['username'], $this->dbConfig['password'], $this->dbConfig['options']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        usleep($latency['connect'] * 1000); // Simulate connect latency

        for ($i = 0; $i < $numOps; $i++) {
            usleep($latency['query'] * 1000); // Simulate query latency
            $result = $pdo->query("SELECT SLEEP(0.001), {$i} as val"); // Actual DB work
            $row = $result->fetch(PDO::FETCH_ASSOC);
        }
        $pdo = null; // Close connection
        return (microtime(true) - $start) * 1000;
    }

    private function benchmarkSyncPrepared(int $numOps, array $latency): float
    {
        $start = microtime(true);
        $pdo = new PDO($this->dbConfig['dsn'], $this->dbConfig['username'], $this->dbConfig['password'], $this->dbConfig['options']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        usleep($latency['connect'] * 1000);

        usleep($latency['prepare'] * 1000);
        $stmt = $pdo->prepare("INSERT INTO async_test (value) VALUES (?)");

        for ($i = 0; $i < $numOps; $i++) {
            usleep($latency['execute'] * 1000);
            $stmt->execute(["test_val_{$i}"]);
        }
        $pdo = null;
        return (microtime(true) - $start) * 1000;
    }

    // --- Asynchronous Task Generators ---

    private function benchmarkAsyncSequentialQuery(int $numOps): float
    {
        $client = new AsyncPDOClient();
        $start = microtime(true);
        run(Async::async(function () use ($client, $numOps) {
            await($client->connect($this->dbConfig));
            for ($i = 0; $i < $numOps; $i++) {
                $result = await($client->query("SELECT SLEEP(0.001), {$i} as val"));
                if ($result instanceof \Rcalicdan\FiberAsync\Database\Result) {
                    $row = $result->fetchAssoc();
                }
            }
            await($client->close());
        })());
        return (microtime(true) - $start) * 1000;
    }

    private function benchmarkAsyncConcurrentQueryWithPool(int $numOps): float
    {
        $clients = $this->createClientPool(); // Create and connect clients
        $start = microtime(true);

        run(Async::async(function () use ($clients, $numOps) {
            $tasks = [];
            for ($i = 0; $i < $numOps; $i++) {
                $client = $clients[$i % $this->numClientsInPool]; // Distribute tasks among clients
                $tasks[] = Async::async(function () use ($client, $i) {
                    $result = await($client->query("SELECT SLEEP(0.001), {$i} as val"));
                    if ($result instanceof \Rcalicdan\FiberAsync\Database\Result) {
                        $row = $result->fetchAssoc();
                    }
                });
            }
            await(concurrent($tasks, $this->numClientsInPool)); // Limit concurrent Fibers to pool size
        })());

        $this->closeClientPool($clients); // Close clients
        return (microtime(true) - $start) * 1000;
    }

    private function benchmarkAsyncConcurrentPreparedWithPool(int $numOps): float
    {
        $clients = $this->createClientPool(); // Create and connect clients
        $start = microtime(true);

        run(Async::async(function () use ($clients, $numOps) {
            $prepareTasks = [];
            
            foreach (array_keys($clients) as $idx) {
                $client = $clients[$idx];
                $prepareTasks[$idx] = Async::async(function () use ($client) {
                    return await($client->prepare("INSERT INTO async_test (value) VALUES (?)"));
                });
            }
            // Await all prepare tasks. $preparedStatements will now be indexed 0 to numClientsInPool-1
            $preparedStatements = await(concurrent($prepareTasks, $this->numClientsInPool));

            $tasks = [];
            for ($i = 0; $i < $numOps; $i++) {
                // Now, $preparedStatements has the correct indices (0 to 9) populated
                // by the results of the prepareTasks in the order they were submitted.
                $stmt = $preparedStatements[$i % $this->numClientsInPool];
                $tasks[] = Async::async(function () use ($stmt, $i) {
                    await($stmt->execute(["test_val_{$i}"]));
                });
            }
            await(concurrent($tasks, $this->numClientsInPool));

            // Close prepared statement cursors
            $closeStmtTasks = [];
            foreach ($preparedStatements as $stmt) { // Iterate over the correctly ordered statements
                $closeStmtTasks[] = Async::async(function () use ($stmt) {
                    await($stmt->closeCursor());
                });
            }
            await(concurrent($closeStmtTasks, $this->numClientsInPool));
        })());

        $this->closeClientPool($clients);
        return (microtime(true) - $start) * 1000;
    }

    // --- Utility Methods ---
    private function printResults(string $testName, float $syncTime, float $asyncTime): void
    {
        if ($asyncTime < 0) {
            printf("  %-30s | Sync: %9.1fms | Async: FAILED\n", $testName, $syncTime);
            return;
        }

        $improvement = $syncTime > 0 ? (($syncTime - $asyncTime) / $syncTime) * 100 : 0;
        $speedRatio = $asyncTime > 0.1 ? $syncTime / $asyncTime : 0;

        printf(
            "  %-30s | Sync: %9.1fms | Async: %9.1fms | 🚀 %5.1f%% faster (%.2fx)\n",
            $testName,
            $syncTime,
            $asyncTime,
            max(0, $improvement),
            $speedRatio
        );
    }

    public function __destruct()
    {
        try {
            $pdo = new PDO($this->dbConfig['dsn'], $this->dbConfig['username'], $this->dbConfig['password']);
            $pdo->exec("DROP TABLE IF EXISTS async_test");
        } catch (\PDOException $e) {
            // ignore
        }
    }
}

$benchmark = new RealisticPDODriverBenchmark();
$benchmark->runBenchmark();
